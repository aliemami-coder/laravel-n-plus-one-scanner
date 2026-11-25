<?php

namespace NPlusOneScanner\Analyzers;

use NPlusOneScanner\Contracts\AstAnalyzerInterface;
use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Data\ParsedFile;
use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Support\ParsedFileCache;
use NPlusOneScanner\Support\PhpParserHelper;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class ApiResourceAnalyzer implements AstAnalyzerInterface
{
    private const BASE_RESOURCE_CLASSES = [
        'Illuminate\\Http\\Resources\\Json\\JsonResource',
        'Illuminate\\Http\\Resources\\Json\\ResourceCollection',
    ];

    public function __construct(
        private readonly ModelRegistry $modelRegistry,
        private readonly PhpParserHelper $parserHelper = new PhpParserHelper,
        private readonly ParsedFileCache $parsedFileCache = new ParsedFileCache,
    ) {}

    public function analyze(string $filePath, string $content): array
    {
        return $this->analyzeParsed($this->parsedFileCache->fromContent($filePath, $content));
    }

    public function analyzeParsed(ParsedFile $parsedFile): array
    {
        $ast = $parsedFile->ast;
        if ($ast === null) {
            return [];
        }

        $filePath = $parsedFile->path;
        $content = $parsedFile->content;

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
            $this->scanStatements($toArray->stmts, [], $inferredModel, $filePath, $content, $issues);
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
     * Walk statements with scoped relationLoaded() guards.
     *
     * @param  array<Node>  $stmts
     * @param  array<string, true>  $guarded
     * @param  list<Issue>  $issues
     */
    private function scanStatements(
        array $stmts,
        array $guarded,
        ?string $inferredModel,
        string $filePath,
        string $content,
        array &$issues,
    ): void {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\If_) {
                $this->scanNode($stmt->cond, $guarded, $inferredModel, $filePath, $content, $issues);

                $positiveGuards = $this->extractGuards($stmt->cond, negated: false);
                $ifGuarded = $guarded;
                foreach ($positiveGuards as $rel) {
                    $ifGuarded[$rel] = true;
                }
                $this->scanStatements($stmt->stmts, $ifGuarded, $inferredModel, $filePath, $content, $issues);

                foreach ($stmt->elseifs as $elseif) {
                    $this->scanNode($elseif->cond, $guarded, $inferredModel, $filePath, $content, $issues);
                    $elseifGuarded = $guarded;
                    foreach ($this->extractGuards($elseif->cond, negated: false) as $rel) {
                        $elseifGuarded[$rel] = true;
                    }
                    $this->scanStatements($elseif->stmts, $elseifGuarded, $inferredModel, $filePath, $content, $issues);
                }

                if ($stmt->else !== null) {
                    $this->scanStatements($stmt->else->stmts, $guarded, $inferredModel, $filePath, $content, $issues);
                }

                // Early-return negative guard: if (! $this->relationLoaded('rel')) { return ...; }
                if ($stmt->else === null && $stmt->elseifs === [] && $this->alwaysTerminates($stmt->stmts)) {
                    foreach ($this->extractGuards($stmt->cond, negated: true) as $rel) {
                        $guarded[$rel] = true;
                    }
                }

                continue;
            }

            $this->scanNode($stmt, $guarded, $inferredModel, $filePath, $content, $issues);
        }
    }

    /**
     * Recursively inspect a node for unguarded `$this->relation` / `$this->resource->relation` accesses.
     *
     * @param  array<string, true>  $guarded
     * @param  list<Issue>  $issues
     */
    private function scanNode(
        Node $node,
        array $guarded,
        ?string $inferredModel,
        string $filePath,
        string $content,
        array &$issues,
    ): void {
        if ($node instanceof Node\Expr\MethodCall
            && $this->isThisOrResource($node->var)
            && $node->name instanceof Node\Identifier
        ) {
            $methodName = $node->name->toString();
            if ($methodName === 'whenLoaded') {
                return;
            }
            if ($methodName === 'when') {
                $firstArg = $node->args[0] ?? null;
                if ($firstArg instanceof Node\Arg) {
                    $whenGuarded = $guarded;
                    foreach ($this->extractGuards($firstArg->value, negated: false) as $rel) {
                        $whenGuarded[$rel] = true;
                    }
                    foreach (array_slice($node->args, 1) as $arg) {
                        $this->scanNode($arg, $whenGuarded, $inferredModel, $filePath, $content, $issues);
                    }

                    return;
                }
            }
        }

        if ($node instanceof Node\Expr\Ternary) {
            $this->scanNode($node->cond, $guarded, $inferredModel, $filePath, $content, $issues);

            if ($node->if !== null) {
                $ifGuarded = $guarded;
                foreach ($this->extractGuards($node->cond, negated: false) as $rel) {
                    $ifGuarded[$rel] = true;
                }
                $this->scanNode($node->if, $ifGuarded, $inferredModel, $filePath, $content, $issues);
            }

            $elseGuarded = $guarded;
            foreach ($this->extractGuards($node->cond, negated: true) as $rel) {
                $elseGuarded[$rel] = true;
            }
            $this->scanNode($node->else, $elseGuarded, $inferredModel, $filePath, $content, $issues);

            return;
        }

        if ($node instanceof Node\Expr\BinaryOp\BooleanAnd) {
            $this->scanNode($node->left, $guarded, $inferredModel, $filePath, $content, $issues);
            $rightGuarded = $guarded;
            foreach ($this->extractGuards($node->left, negated: false) as $rel) {
                $rightGuarded[$rel] = true;
            }
            $this->scanNode($node->right, $rightGuarded, $inferredModel, $filePath, $content, $issues);

            return;
        }

        if ($node instanceof Node\Expr\PropertyFetch
            && $this->isThisOrResource($node->var)
            && $node->name instanceof Node\Identifier
        ) {
            $relationName = $node->name->toString();
            if (! isset($guarded[$relationName])) {
                $resolved = $this->resolveRelation($inferredModel, $relationName);
                if ($resolved !== null) {
                    [$modelName, $severity] = $resolved;
                    $line = $node->getStartLine();
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

            return;
        }

        foreach ($node->getSubNodeNames() as $subName) {
            $sub = $node->{$subName};
            if ($sub instanceof Node) {
                $this->scanNode($sub, $guarded, $inferredModel, $filePath, $content, $issues);
            } elseif (is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof Node) {
                        $this->scanNode($item, $guarded, $inferredModel, $filePath, $content, $issues);
                    }
                }
            }
        }
    }

    /**
     * Extract relation names from `$this->relationLoaded('rel')` or `! $this->relationLoaded('rel')`.
     *
     * @return list<string>
     */
    private function extractGuards(Node $expr, bool $negated): array
    {
        if ($negated) {
            if ($expr instanceof Node\Expr\BooleanNot) {
                return $this->extractGuards($expr->expr, negated: false);
            }

            return [];
        }

        if ($expr instanceof Node\Expr\MethodCall
            && $this->isThisOrResource($expr->var)
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'relationLoaded'
        ) {
            $first = $expr->args[0] ?? null;
            if ($first instanceof Node\Arg && $first->value instanceof Node\Scalar\String_) {
                return [$first->value->value];
            }
        }

        if ($expr instanceof Node\Expr\BinaryOp\BooleanAnd) {
            return array_values(array_unique([
                ...$this->extractGuards($expr->left, negated: false),
                ...$this->extractGuards($expr->right, negated: false),
            ]));
        }

        return [];
    }

    /**
     * @param  array<Node\Stmt>  $stmts
     */
    private function alwaysTerminates(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_ || $stmt instanceof Node\Stmt\Throw_) {
                return true;
            }
        }

        return false;
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
