<?php

namespace NPlusOneScanner\Analyzers;

use NPlusOneScanner\Analysis\RelationGuardAnalyzer;
use NPlusOneScanner\Analysis\ScopeContext;
use NPlusOneScanner\Contracts\AstAnalyzerInterface;
use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Data\ParsedFile;
use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Support\ParsedFileCache;
use NPlusOneScanner\Support\PhpParserHelper;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class PhpLoopAnalyzer implements AstAnalyzerInterface
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

    private const EAGER_LOAD_IN_LOOP_METHODS = [
        'load',
        'loadMissing',
        'loadCount',
        'loadExists',
        'loadSum',
        'loadAvg',
        'loadMax',
        'loadMin',
        'loadMorph',
        'loadMorphCount',
    ];

    public function __construct(
        private readonly ModelRegistry $modelRegistry,
        private readonly PhpParserHelper $parserHelper = new PhpParserHelper,
        private readonly ParsedFileCache $parsedFileCache = new ParsedFileCache,
        private readonly RelationGuardAnalyzer $guardAnalyzer = new RelationGuardAnalyzer,
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
            if ($stmt instanceof Node\Stmt\Foreach_) {
                $scope->processStatement($stmt);
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
                $scope->processStatement($stmt);
                $this->scanStatements($stmt->stmts, clone $scope, $filePath, $content, true, $issues);

                continue;
            }

            if ($stmt instanceof Node\Stmt\If_) {
                $scope->processStatement($stmt);
                $branchScopes = [];
                $targetResolver = static fn (Node\Expr $expr): ?string => ($expr instanceof Node\Expr\Variable && is_string($expr->name)) ? $expr->name : null;

                $guardedRelations = $this->guardAnalyzer->extractPositiveGuards($stmt->cond, $targetResolver);
                $ifScope = clone $scope;
                foreach ($guardedRelations as [$varName, $relation]) {
                    $ifScope->addEagerLoads($varName, [$relation]);
                }
                $this->scanStatements($stmt->stmts, $ifScope, $filePath, $content, $inLoop, $issues);
                $branchScopes[] = $ifScope;

                foreach ($stmt->elseifs as $elseif) {
                    $elseifScope = clone $scope;
                    foreach ($this->guardAnalyzer->extractPositiveGuards($elseif->cond, $targetResolver) as [$varName, $relation]) {
                        $elseifScope->addEagerLoads($varName, [$relation]);
                    }
                    $this->scanStatements($elseif->stmts, $elseifScope, $filePath, $content, $inLoop, $issues);
                    $branchScopes[] = $elseifScope;
                }

                if ($stmt->else !== null) {
                    $elseScope = clone $scope;
                    foreach ($this->guardAnalyzer->extractNegativeGuards($stmt->cond, $targetResolver) as [$varName, $relation]) {
                        $elseScope->addEagerLoads($varName, [$relation]);
                    }
                    $this->scanStatements($stmt->else->stmts, $elseScope, $filePath, $content, $inLoop, $issues);
                    $branchScopes[] = $elseScope;
                } else {
                    $branchScopes[] = clone $scope;
                }

                $scope->mergeBranches($branchScopes);

                if ($stmt->else === null && $stmt->elseifs === [] && $this->alwaysTerminates($stmt->stmts, $inLoop)) {
                    foreach ($this->guardAnalyzer->extractNegativeGuards($stmt->cond, $targetResolver) as [$varName, $relation]) {
                        $scope->addEagerLoads($varName, [$relation]);
                    }
                }

                continue;
            }

            // Detect Collection higher-order / callback loops: $items->each(fn ($item) => ...)
            $this->scanCollectionCallbacks($stmt, $scope, $filePath, $content, $inLoop, $issues);

            if ($inLoop) {
                $this->inspectNodeInLoop($stmt, $scope, $filePath, $content, $issues);
            }

            $scope->processStatement($stmt);
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

        // Detect $item->load() / loadMissing() / loadCount() / loadSum() etc. inside loop
        $this->detectEagerLoadCallsInLoop($node, $scope, $filePath, $content, $nodeFinder, $issues);

        // Detect $item->relation property access inside loop
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
     * Detect `$model->load()`, `loadMissing()`, `loadCount()`, `loadSum()`, etc. called inside loops.
     *
     * @param  list<Issue>  $issues
     */
    private function detectEagerLoadCallsInLoop(
        Node $node,
        ScopeContext $scope,
        string $filePath,
        string $content,
        NodeFinder $nodeFinder,
        array &$issues,
    ): void {
        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $nodeFinder->findInstanceOf([$node], Node\Expr\MethodCall::class);

        foreach ($calls as $call) {
            if (! $call->var instanceof Node\Expr\Variable
                || ! is_string($call->var->name)
                || ! $call->name instanceof Node\Identifier
            ) {
                continue;
            }

            $methodName = $call->name->toString();
            if (! in_array($methodName, self::EAGER_LOAD_IN_LOOP_METHODS, true)) {
                continue;
            }

            $varName = $call->var->name;
            $relations = PhpParserHelper::extractRelationNamesFromArgs($call->args);
            $primaryRelation = $relations[0] ?? null;

            // If loadMissing() is called for relations already guaranteed eager-loaded before the loop, skip.
            if ($methodName === 'loadMissing' && $relations !== []) {
                $allAlreadyLoaded = true;
                foreach ($relations as $rel) {
                    if (! $scope->isRelationEagerLoaded($varName, $rel)) {
                        $allAlreadyLoaded = false;
                        break;
                    }
                }
                if ($allAlreadyLoaded) {
                    continue;
                }
            }

            $modelName = null;
            $severity = Issue::SEVERITY_MEDIUM;

            $varModel = $scope->getVariableModel($varName);
            if ($varModel !== null) {
                $modelDef = $this->modelRegistry->findByClassName($varModel);
                $modelName = $modelDef?->shortName ?? PhpParserHelper::shortName($varModel);
                $severity = Issue::SEVERITY_HIGH;
            } elseif ($primaryRelation !== null) {
                $resolved = $this->resolveRelationOwner($varName, $primaryRelation, $scope);
                if ($resolved !== null) {
                    [$modelName, $severity] = $resolved;
                } elseif ($methodName === 'load') {
                    // Avoid false positives on non-Eloquent ->load() calls when neither model nor relation is known.
                    continue;
                }
            } elseif ($methodName === 'load') {
                continue;
            }

            $line = $call->getStartLine();
            $relLabel = $primaryRelation ?? 'relation';
            $withEquivalent = match ($methodName) {
                'loadCount' => "withCount('{$relLabel}')",
                'loadExists' => "withExists('{$relLabel}')",
                'loadSum' => "withSum('{$relLabel}', 'column')",
                'loadAvg' => "withAvg('{$relLabel}', 'column')",
                'loadMax' => "withMax('{$relLabel}', 'column')",
                'loadMin' => "withMin('{$relLabel}', 'column')",
                default => "with('{$relLabel}')",
            };

            $issues[] = new Issue(
                file: $filePath,
                line: $line,
                type: Issue::TYPE_EAGER_LOAD_IN_LOOP,
                severity: $severity,
                model: $modelName,
                relation: $primaryRelation,
                message: "Calling `\${$varName}->{$methodName}('{$relLabel}')` inside a loop executes a SQL query per iteration (N+1 query).",
                suggestion: "Move `->{$methodName}('{$relLabel}')` before the loop onto the collection (e.g. `\$collection->{$methodName}('{$relLabel}')`) or use `->{$withEquivalent}` in the initial query.",
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
     * @param  array<Node\Stmt>  $stmts
     */
    private function alwaysTerminates(array $stmts, bool $inLoop): bool
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_ || $stmt instanceof Node\Stmt\Throw_) {
                return true;
            }
            if ($inLoop && ($stmt instanceof Node\Stmt\Continue_ || $stmt instanceof Node\Stmt\Break_)) {
                return true;
            }
        }

        return false;
    }
}
