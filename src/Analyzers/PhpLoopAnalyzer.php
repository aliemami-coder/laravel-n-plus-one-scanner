<?php

namespace NPlusOneScanner\Analyzers;

use NPlusOneScanner\Analysis\ScopeContext;
use NPlusOneScanner\Contracts\AnalyzerInterface;
use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Support\PhpParserHelper;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class PhpLoopAnalyzer implements AnalyzerInterface
{
    private const COLLECTION_LOOP_METHODS = [
        'each',
        'map',
        'filter',
        'flatMap',
        'transform',
        'reject',
        'every',
        'some',
        'partition',
        'mapWithKeys',
    ];

    private const QUERY_TERMINAL_METHODS = [
        'get',
        'first',
        'firstOrFail',
        'find',
        'findOrFail',
        'count',
        'exists',
        'doesntExist',
        'pluck',
        'sum',
        'avg',
        'min',
        'max',
        'value',
        'paginate',
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

        /** @var list<Node\FunctionLike> $functions */
        $functions = $nodeFinder->findInstanceOf($ast, Node\FunctionLike::class);

        if ($functions === []) {
            $scope = new ScopeContext($this->modelRegistry);
            $this->scanStatements($ast, $scope, $filePath, $content, false, $issues);

            return $issues;
        }

        foreach ($functions as $fn) {
            $stmts = $fn->getStmts();
            if ($stmts === null) {
                continue;
            }

            $scope = new ScopeContext($this->modelRegistry);
            $this->seedParameters($fn, $scope);
            $this->scanStatements($stmts, $scope, $filePath, $content, false, $issues);
        }

        return $issues;
    }

    private function seedParameters(Node\FunctionLike $fn, ScopeContext $scope): void
    {
        foreach ($fn->getParams() as $param) {
            if (! $param->var instanceof Node\Expr\Variable || ! is_string($param->var->name)) {
                continue;
            }

            if ($param->type instanceof Node\Name) {
                $resolved = PhpParserHelper::resolveClassName($param->type);
                if ($resolved !== null && $this->modelRegistry->isKnownModelClass($resolved)) {
                    $scope->setVariableModel($param->var->name, $resolved);
                }
            }
        }
    }

    /**
     * @param  array<Node>  $stmts
     * @param  list<Issue>  $issues
     */
    private function scanStatements(
        array $stmts,
        ScopeContext $scope,
        string $filePath,
        string $content,
        bool $inLoop,
        array &$issues,
    ): void {
        foreach ($stmts as $stmt) {
            $scope->processStatement($stmt);

            if ($stmt instanceof Node\Stmt\Foreach_) {
                $itemVar = ($stmt->valueVar instanceof Node\Expr\Variable && is_string($stmt->valueVar->name))
                    ? $stmt->valueVar->name
                    : null;

                $childScope = $itemVar !== null
                    ? $scope->enterLoop($stmt->expr, $itemVar)
                    : clone $scope;

                $this->scanStatements($stmt->stmts, $childScope, $filePath, $content, true, $issues);

                continue;
            }

            if ($stmt instanceof Node\Stmt\For_ || $stmt instanceof Node\Stmt\While_ || $stmt instanceof Node\Stmt\Do_) {
                $this->scanStatements($stmt->stmts, clone $scope, $filePath, $content, true, $issues);

                continue;
            }

            if ($stmt instanceof Node\Stmt\If_) {
                $branchScopes = [];

                $guardedRelations = $this->extractRelationLoadedGuards($stmt->cond);
                $ifScope = clone $scope;
                foreach ($guardedRelations as [$varName, $relation]) {
                    $ifScope->addEagerLoads($varName, [$relation]);
                }
                $this->scanStatements($stmt->stmts, $ifScope, $filePath, $content, $inLoop, $issues);
                $branchScopes[] = $ifScope;

                foreach ($stmt->elseifs as $elseif) {
                    $elseifScope = clone $scope;
                    $this->scanStatements($elseif->stmts, $elseifScope, $filePath, $content, $inLoop, $issues);
                    $branchScopes[] = $elseifScope;
                }

                if ($stmt->else !== null) {
                    $elseScope = clone $scope;
                    $this->scanStatements($stmt->else->stmts, $elseScope, $filePath, $content, $inLoop, $issues);
                    $branchScopes[] = $elseScope;
                } else {
                    $branchScopes[] = clone $scope;
                }

                $scope->mergeBranches($branchScopes);

                continue;
            }

            // Detect Collection higher-order / callback loops: $items->each(fn ($item) => ...)
            $this->scanCollectionCallbacks($stmt, $scope, $filePath, $content, $inLoop, $issues);

            if ($inLoop) {
                $this->inspectNodeInLoop($stmt, $scope, $filePath, $content, $issues);
            }
        }
    }

    /**
     * @param  list<Issue>  $issues
     */
    private function scanCollectionCallbacks(
        Node $node,
        ScopeContext $scope,
        string $filePath,
        string $content,
        bool $inLoop,
        array &$issues,
    ): void {
        $nodeFinder = new NodeFinder;
        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $nodeFinder->findInstanceOf([$node], Node\Expr\MethodCall::class);

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $methodName = $call->name->toString();
            if (! in_array($methodName, self::COLLECTION_LOOP_METHODS, true)) {
                continue;
            }

            $firstArg = $call->args[0] ?? null;
            if (! $firstArg instanceof Node\Arg) {
                continue;
            }

            $callback = $firstArg->value;
            if ($callback instanceof Node\Expr\Closure || $callback instanceof Node\Expr\ArrowFunction) {
                $params = $callback->getParams();
                $itemVar = (isset($params[0]) && $params[0]->var instanceof Node\Expr\Variable && is_string($params[0]->var->name))
                    ? $params[0]->var->name
                    : null;

                $childScope = $itemVar !== null
                    ? $scope->enterLoop($call->var, $itemVar)
                    : clone $scope;

                if ($callback instanceof Node\Expr\ArrowFunction) {
                    $this->inspectNodeInLoop($callback->expr, $childScope, $filePath, $content, $issues);
                } else {
                    $this->scanStatements($callback->stmts, $childScope, $filePath, $content, true, $issues);
                }
            }
        }
    }

    /**
     * @param  list<Issue>  $issues
     */
    private function inspectNodeInLoop(
        Node $node,
        ScopeContext $scope,
        string $filePath,
        string $content,
        array &$issues,
    ): void {
        $nodeFinder = new NodeFinder;

        // Collect property-write targets ($item->prop = ...) so we don't flag assignments as reads.
        $writeTargetIds = [];
        /** @var list<Node\Expr\Assign> $assigns */
        $assigns = $nodeFinder->findInstanceOf([$node], Node\Expr\Assign::class);
        foreach ($assigns as $assign) {
            if ($assign->var instanceof Node\Expr\PropertyFetch) {
                $writeTargetIds[spl_object_id($assign->var)] = true;
            }
        }

        // 1. Detect $item->relation()->count()/get()/first()/exists() inside loop
        /** @var list<Node\Expr\MethodCall> $methodCalls */
        $methodCalls = $nodeFinder->findInstanceOf([$node], Node\Expr\MethodCall::class);
        foreach ($methodCalls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $terminalName = $call->name->toString();
            if (! in_array($terminalName, self::QUERY_TERMINAL_METHODS, true)) {
                continue;
            }

            if ($call->var instanceof Node\Expr\MethodCall
                && $call->var->var instanceof Node\Expr\Variable
                && is_string($call->var->var->name)
                && $call->var->name instanceof Node\Identifier
            ) {
                $varName = $call->var->var->name;
                $relationName = $call->var->name->toString();
                $resolved = $this->resolveRelationOwner($varName, $relationName, $scope);

                if ($resolved !== null) {
                    [$modelName, $severity] = $resolved;
                    $line = $call->getStartLine();
                    $issues[] = new Issue(
                        file: $filePath,
                        line: $line,
                        type: Issue::TYPE_LAZY_LOADING_IN_LOOP,
                        severity: $severity,
                        model: $modelName,
                        relation: $relationName,
                        message: "Calling `->{$relationName}()->{$terminalName}()` inside a loop triggers a SQL query per iteration.",
                        suggestion: "Eager load `{$relationName}` (or use `withCount('{$relationName}')`) before the loop and access the loaded property/count instead.",
                        snippet: PhpParserHelper::getLineSnippet($content, $line),
                    );
                }
            }
        }

        // 2. Detect $item->relation property access inside loop
        /** @var list<Node\Expr\PropertyFetch> $propFetches */
        $propFetches = $nodeFinder->findInstanceOf([$node], Node\Expr\PropertyFetch::class);
        foreach ($propFetches as $fetch) {
            if (isset($writeTargetIds[spl_object_id($fetch)])) {
                continue;
            }

            if (! $fetch->var instanceof Node\Expr\Variable || ! is_string($fetch->var->name)) {
                continue;
            }

            if (! $fetch->name instanceof Node\Identifier) {
                continue;
            }

            $varName = $fetch->var->name;
            $relationName = $fetch->name->toString();

            if ($scope->isRelationEagerLoaded($varName, $relationName)) {
                continue;
            }

            $resolved = $this->resolveRelationOwner($varName, $relationName, $scope);
            if ($resolved === null) {
                continue;
            }

            [$modelName, $severity] = $resolved;
            $line = $fetch->getStartLine();

            $issues[] = new Issue(
                file: $filePath,
                line: $line,
                type: Issue::TYPE_LAZY_LOADING_IN_LOOP,
                severity: $severity,
                model: $modelName,
                relation: $relationName,
                message: "Potential N+1 lazy loading: accessing `\${$varName}->{$relationName}` inside a loop without eager loading.",
                suggestion: "Add `->with('{$relationName}')` to the query or call `->loadMissing('{$relationName}')` before the loop.",
                snippet: PhpParserHelper::getLineSnippet($content, $line),
            );
        }
    }

    /**
     * @return array{0: string, 1: string}|null [ModelShortName, Severity]
     */
    private function resolveRelationOwner(string $varName, string $relationName, ScopeContext $scope): ?array
    {
        $modelClass = $scope->getVariableModel($varName);
        if ($modelClass !== null) {
            $model = $this->modelRegistry->findByClassName($modelClass);
            if ($model !== null && $model->hasRelation($relationName)) {
                return [$model->shortName, Issue::SEVERITY_HIGH];
            }

            return null;
        }

        $candidates = $this->modelRegistry->findModelsWithRelation($relationName);
        if ($candidates === []) {
            return null;
        }

        // If the variable name matches a candidate model's short name (e.g. $customer -> Customer)
        foreach ($candidates as $candidate) {
            if (strcasecmp($varName, $candidate->shortName) === 0) {
                if ($candidate->isDefaultEagerLoaded($relationName)) {
                    return null;
                }

                return [$candidate->shortName, Issue::SEVERITY_MEDIUM];
            }
        }

        // If the relation name uniquely belongs to a single model in the project
        if (count($candidates) === 1 && ! $candidates[0]->isDefaultEagerLoaded($relationName)) {
            return [$candidates[0]->shortName, Issue::SEVERITY_MEDIUM];
        }

        return null;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function extractRelationLoadedGuards(Node\Expr $cond): array
    {
        $guards = [];
        $nodeFinder = new NodeFinder;
        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $nodeFinder->findInstanceOf([$cond], Node\Expr\MethodCall::class);

        foreach ($calls as $call) {
            if ($call->var instanceof Node\Expr\Variable
                && is_string($call->var->name)
                && $call->name instanceof Node\Identifier
                && $call->name->toString() === 'relationLoaded'
            ) {
                $first = $call->args[0] ?? null;
                if ($first instanceof Node\Arg && $first->value instanceof Node\Scalar\String_) {
                    $guards[] = [$call->var->name, $first->value->value];
                }
            }
        }

        return $guards;
    }
}
