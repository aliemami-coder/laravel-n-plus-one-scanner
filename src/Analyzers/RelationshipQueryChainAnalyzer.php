<?php

namespace NPlusOneScanner\Analyzers;

use NPlusOneScanner\Analysis\ScopeContext;
use NPlusOneScanner\Contracts\AstAnalyzerInterface;
use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Data\ParsedFile;
use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Support\ParsedFileCache;
use NPlusOneScanner\Support\PhpParserHelper;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class RelationshipQueryChainAnalyzer implements AstAnalyzerInterface
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

    private const TERMINAL_METHODS = [
        'count',
        'exists',
        'doesntExist',
        'sum',
        'avg',
        'max',
        'min',
        'get',
        'first',
        'firstOrFail',
        'find',
        'findOrFail',
        'pluck',
        'value',
        'paginate',
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

                $ifScope = clone $scope;
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

            $this->scanCollectionCallbacks($stmt, $scope, $filePath, $content, $issues);

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
        array &$issues,
    ): void {
        $nodeFinder = new NodeFinder;
        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $nodeFinder->findInstanceOf([$node], Node\Expr\MethodCall::class);

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier
                || ! in_array($call->name->toString(), self::COLLECTION_LOOP_METHODS, true)
            ) {
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
        /** @var list<Node\Expr\MethodCall> $methodCalls */
        $methodCalls = $nodeFinder->findInstanceOf([$node], Node\Expr\MethodCall::class);

        $reportedKeys = [];

        foreach ($methodCalls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $terminalName = $call->name->toString();
            if (! in_array($terminalName, self::TERMINAL_METHODS, true)) {
                continue;
            }

            $root = $this->findRootRelationCall($call);
            if ($root === null) {
                continue;
            }

            [$varName, $relationName, $hasIntermediateChain] = $root;
            $resolved = $this->resolveRelationOwner($varName, $relationName, $scope);
            if ($resolved === null) {
                continue;
            }

            [$modelName, $severity] = $resolved;
            $line = $call->getStartLine();
            $key = $line.':'.$relationName.':'.$terminalName;
            if (isset($reportedKeys[$key])) {
                continue;
            }
            $reportedKeys[$key] = true;

            $chainLabel = $hasIntermediateChain
                ? "->{$relationName}()->...->{$terminalName}()"
                : "->{$relationName}()->{$terminalName}()";

            $columnArg = $this->extractFirstStringArg($call);

            $issues[] = new Issue(
                file: $filePath,
                line: $line,
                type: Issue::TYPE_LAZY_LOADING_IN_LOOP,
                severity: $severity,
                model: $modelName,
                relation: $relationName,
                message: "Relationship query chain `{$chainLabel}` inside a loop executes a SQL query per iteration.",
                suggestion: $this->buildSuggestion($relationName, $terminalName, $hasIntermediateChain, $columnArg),
                snippet: PhpParserHelper::getLineSnippet($content, $line),
            );
        }
    }

    /**
     * Walk backward from a terminal MethodCall (e.g. `->count()`) through any chained builder
     * calls (`->where()->latest()`) to find `$var->relation()`.
     *
     * @return array{0: string, 1: string, 2: bool}|null [varName, relationName, hasIntermediateChain]
     */
    private function findRootRelationCall(Node\Expr\MethodCall $terminalCall): ?array
    {
        $steps = 0;
        $current = $terminalCall->var;

        while ($current instanceof Node\Expr\MethodCall) {
            if ($current->var instanceof Node\Expr\Variable
                && is_string($current->var->name)
                && $current->name instanceof Node\Identifier
            ) {
                return [$current->var->name, $current->name->toString(), $steps > 0];
            }

            $steps++;
            $current = $current->var;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null
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

        foreach ($candidates as $candidate) {
            if (strcasecmp($varName, $candidate->shortName) === 0) {
                return [$candidate->shortName, Issue::SEVERITY_MEDIUM];
            }
        }

        if (count($candidates) === 1) {
            return [$candidates[0]->shortName, Issue::SEVERITY_MEDIUM];
        }

        return null;
    }

    private function extractFirstStringArg(Node\Expr\MethodCall $call): ?string
    {
        $first = $call->args[0] ?? null;
        if ($first instanceof Node\Arg && $first->value instanceof Node\Scalar\String_) {
            return $first->value->value;
        }

        return null;
    }

    private function buildSuggestion(
        string $relation,
        string $terminal,
        bool $hasIntermediateChain,
        ?string $columnArg,
    ): string {
        $col = $columnArg ?? 'column';
        $closureHint = $hasIntermediateChain ? ' (pass a constraint closure if filtering is needed)' : '';

        return match ($terminal) {
            'count' => "Use `->withCount('{$relation}')` (or `->loadCount('{$relation}')` on a Collection){$closureHint} and read `->{$relation}_count`.",
            'exists', 'doesntExist' => "Use `->withExists('{$relation}')` (or `->loadExists('{$relation}')`){$closureHint} and read `->{$relation}_exists`.",
            'sum' => "Use `->withSum('{$relation}', '{$col}')` (or `->loadSum('{$relation}', '{$col}')`){$closureHint} and read `->{$relation}_sum_{$col}`.",
            'avg' => "Use `->withAvg('{$relation}', '{$col}')` (or `->loadAvg('{$relation}', '{$col}')`){$closureHint} and read `->{$relation}_avg_{$col}`.",
            'max' => "Use `->withMax('{$relation}', '{$col}')` (or `->loadMax('{$relation}', '{$col}')`){$closureHint} and read `->{$relation}_max_{$col}`.",
            'min' => "Use `->withMin('{$relation}', '{$col}')` (or `->loadMin('{$relation}', '{$col}')`){$closureHint} and read `->{$relation}_min_{$col}`.",
            default => "Use `->with('{$relation}')` (or `->loadMissing('{$relation}')` on a Collection){$closureHint} before the loop and work on the loaded relation.",
        };
    }
}
