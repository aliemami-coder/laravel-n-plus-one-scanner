<?php

namespace NPlusOneScanner\Analyzers;

use NPlusOneScanner\Contracts\AnalyzerInterface;
use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Support\PhpParserHelper;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class ApiResourceAnalyzer implements AnalyzerInterface
{
    private const BASE_RESOURCE_CLASSES = [
        'Illuminate\\Http\\Resources\\Json\\JsonResource',
        'Illuminate\\Http\\Resources\\Json\\ResourceCollection',
    ];

    public function __construct(
        private readonly ModelRegistry $modelRegistry,
        private readonly PhpParserHelper $parserHelper = new PhpParserHelper,
    ) {}

    public function analyze(string $filePath, string $content): array
    {
        $ast = $this->parserHelper->parse($content);
        if ($ast === null) {
            return [];
        }

        $issues = [];
        $nodeFinder = new NodeFinder;
        /** @var list<Node\Stmt\Class_> $classes */
        $classes = $nodeFinder->findInstanceOf($ast, Node\Stmt\Class_::class);

        foreach ($classes as $class) {
            if ($class->name === null || ! $this->isApiResource($class)) {
                continue;
            }

            $toArray = $class->getMethod('toArray');
            if ($toArray === null || $toArray->stmts === null) {
                continue;
            }

            $inferredModel = $this->inferModelFromResource($class);
            $safeNodeIds = $this->collectSafeNodesInsideWhenLoaded($toArray->stmts, $nodeFinder);
            $guardedRelations = $this->collectMethodLevelGuards($toArray->stmts, $nodeFinder);

            /** @var list<Node\Expr\PropertyFetch> $propFetches */
            $propFetches = $nodeFinder->findInstanceOf($toArray->stmts, Node\Expr\PropertyFetch::class);

            foreach ($propFetches as $fetch) {
                if (isset($safeNodeIds[spl_object_id($fetch)])) {
                    continue;
                }

                if (! $this->isThisOrResource($fetch->var) || ! $fetch->name instanceof Node\Identifier) {
                    continue;
                }

                $relationName = $fetch->name->toString();
                if (in_array($relationName, $guardedRelations, true)) {
                    continue;
                }

                $resolved = $this->resolveRelation($inferredModel, $relationName);
                if ($resolved === null) {
                    continue;
                }

                [$modelName, $severity] = $resolved;
                $line = $fetch->getStartLine();

                $issues[] = new Issue(
                    file: $filePath,
                    line: $line,
                    type: Issue::TYPE_MISSING_WHEN_LOADED,
                    severity: $severity,
                    model: $modelName,
                    relation: $relationName,
                    message: "API Resource accesses `\$this->{$relationName}` without `whenLoaded('{$relationName}')`, risking N+1 queries on collections.",
                    suggestion: "Use `\$this->whenLoaded('{$relationName}')` or guard with `\$this->relationLoaded('{$relationName}')`.",
                    snippet: PhpParserHelper::getLineSnippet($content, $line),
                );
            }
        }

        return $issues;
    }

    private function isApiResource(Node\Stmt\Class_ $class): bool
    {
        if ($class->extends === null) {
            return false;
        }

        $parentFqn = PhpParserHelper::resolveClassName($class->extends) ?? '';
        if (in_array($parentFqn, self::BASE_RESOURCE_CLASSES, true)) {
            return true;
        }

        $shortParent = PhpParserHelper::shortName($parentFqn);

        return in_array($shortParent, ['JsonResource', 'ResourceCollection', 'BaseResource'], true);
    }

    private function inferModelFromResource(Node\Stmt\Class_ $class): ?string
    {
        $doc = $class->getDocComment()?->getText() ?? '';
        if (preg_match('/@mixin\s+([\\\\A-Za-z0-9_]+)/', $doc, $matches)) {
            $candidate = $this->modelRegistry->findByClassName($matches[1]);
            if ($candidate !== null) {
                return $candidate->shortName;
            }
        }

        $resourceName = $class->name?->toString() ?? '';
        $stripped = preg_replace('/(Collection|Resource)$/', '', $resourceName) ?? $resourceName;
        if ($stripped !== '' && $this->modelRegistry->isKnownModelClass($stripped)) {
            return $stripped;
        }

        return null;
    }

    /**
     * Collect object IDs of PropertyFetch nodes that sit inside `$this->whenLoaded(...)`
     * or `$this->when($this->relationLoaded(...), ...)`.
     *
     * @param  list<Node\Stmt>  $stmts
     * @return array<int, true>
     */
    private function collectSafeNodesInsideWhenLoaded(array $stmts, NodeFinder $nodeFinder): array
    {
        $safeIds = [];
        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $nodeFinder->findInstanceOf($stmts, Node\Expr\MethodCall::class);

        foreach ($calls as $call) {
            if (! $this->isThisOrResource($call->var) || ! $call->name instanceof Node\Identifier) {
                continue;
            }

            $methodName = $call->name->toString();
            $isSafeWrapper = $methodName === 'whenLoaded'
                || ($methodName === 'when' && $this->containsRelationLoaded($call->args[0] ?? null, $nodeFinder));

            if (! $isSafeWrapper) {
                continue;
            }

            /** @var list<Node\Expr\PropertyFetch> $innerFetches */
            $innerFetches = $nodeFinder->findInstanceOf($call->args, Node\Expr\PropertyFetch::class);
            foreach ($innerFetches as $inner) {
                $safeIds[spl_object_id($inner)] = true;
            }
        }

        return $safeIds;
    }

    private function containsRelationLoaded(?Node $node, NodeFinder $nodeFinder): bool
    {
        if ($node === null) {
            return false;
        }

        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $nodeFinder->findInstanceOf([$node], Node\Expr\MethodCall::class);
        foreach ($calls as $call) {
            if ($call->name instanceof Node\Identifier && $call->name->toString() === 'relationLoaded') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Node\Stmt>  $stmts
     * @return list<string>
     */
    private function collectMethodLevelGuards(array $stmts, NodeFinder $nodeFinder): array
    {
        $guarded = [];
        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $nodeFinder->findInstanceOf($stmts, Node\Expr\MethodCall::class);

        foreach ($calls as $call) {
            if ($call->name instanceof Node\Identifier && $call->name->toString() === 'relationLoaded') {
                $first = $call->args[0] ?? null;
                if ($first instanceof Node\Arg && $first->value instanceof Node\Scalar\String_) {
                    $guarded[] = $first->value->value;
                }
            }
        }

        return $guarded;
    }

    private function isThisOrResource(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\Variable && $expr->name === 'this') {
            return true;
        }

        return $expr instanceof Node\Expr\PropertyFetch
            && $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'resource';
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function resolveRelation(?string $inferredModel, string $relationName): ?array
    {
        if ($inferredModel !== null) {
            $model = $this->modelRegistry->findByClassName($inferredModel);
            if ($model !== null && $model->hasRelation($relationName)) {
                return $model->isDefaultEagerLoaded($relationName)
                    ? null
                    : [$model->shortName, Issue::SEVERITY_HIGH];
            }

            return null;
        }

        $candidates = $this->modelRegistry->findModelsWithRelation($relationName);
        if (count($candidates) === 1 && ! $candidates[0]->isDefaultEagerLoaded($relationName)) {
            return [$candidates[0]->shortName, Issue::SEVERITY_MEDIUM];
        }

        return null;
    }
}
