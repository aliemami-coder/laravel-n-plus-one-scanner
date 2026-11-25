<?php

namespace NPlusOneScanner\Discovery;

use NPlusOneScanner\Analysis\ScopeContext;
use NPlusOneScanner\Support\ParsedFileCache;
use NPlusOneScanner\Support\PhpParserHelper;
use PhpParser\Node;
use PhpParser\NodeFinder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Maps Blade view names (e.g. 'customers.index') to variable contexts passed from Controllers/Routes.
 */
final class ControllerViewMap
{
    /** @var array<string, list<array<string, array{model: ?string, with: list<string>}>>> */
    private array $viewCallSites = [];

    public function __construct(
        private readonly ModelRegistry $modelRegistry,
        private readonly PhpParserHelper $parserHelper = new PhpParserHelper,
        private readonly ParsedFileCache $parsedFileCache = new ParsedFileCache,
    ) {}

    /**
     * @param  list<string>  $directories
     */
    public function discover(array $directories): void
    {
        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $this->scanFile($file->getPathname());
                }
            }
        }
    }

    public function scanFile(string $filePath): void
    {
        $parsed = $this->parsedFileCache->get($filePath);
        if ($parsed === null || $parsed->ast === null) {
            return;
        }

        $ast = $parsed->ast;

        $nodeFinder = new NodeFinder;
        /** @var list<Node\FunctionLike> $functions */
        $functions = $nodeFinder->findInstanceOf($ast, Node\FunctionLike::class);

        foreach ($functions as $fn) {
            $stmts = $fn->getStmts();
            if ($stmts === null) {
                continue;
            }

            $scope = new ScopeContext($this->modelRegistry);
            foreach ($stmts as $stmt) {
                $scope->processStatement($stmt);
            }

            /** @var list<Node\Expr\FuncCall> $funcCalls */
            $funcCalls = $nodeFinder->findInstanceOf($stmts, Node\Expr\FuncCall::class);
            foreach ($funcCalls as $call) {
                if ($call->name instanceof Node\Name && $call->name->toString() === 'view') {
                    $this->recordViewCall($call->args, $scope);
                }
            }

            /** @var list<Node\Expr\StaticCall> $staticCalls */
            $staticCalls = $nodeFinder->findInstanceOf($stmts, Node\Expr\StaticCall::class);
            foreach ($staticCalls as $call) {
                $className = PhpParserHelper::resolveClassName($call->class) ?? '';
                if (PhpParserHelper::shortName($className) === 'View'
                    && $call->name instanceof Node\Identifier
                    && $call->name->toString() === 'make'
                ) {
                    $this->recordViewCall($call->args, $scope);
                }
            }
        }
    }

    /**
     * Returns all recorded per-call-site variable contexts for a view.
     *
     * @return list<array<string, array{model: ?string, with: list<string>}>>
     */
    public function getCallSitesForView(string $viewName): array
    {
        return $this->viewCallSites[$viewName] ?? [];
    }

    /**
     * Aggregates variable contexts across all call sites of a view, keeping only
     * eager loads guaranteed in EVERY call site that passes the variable.
     *
     * @return array<string, array{model: ?string, with: list<string>}>
     */
    public function getContextForView(string $viewName): array
    {
        $callSites = $this->viewCallSites[$viewName] ?? [];
        if ($callSites === []) {
            return [];
        }

        $varNames = [];
        foreach ($callSites as $site) {
            foreach (array_keys($site) as $varName) {
                $varNames[$varName] = true;
            }
        }

        $aggregated = [];
        foreach (array_keys($varNames) as $varName) {
            $models = [];
            $withLists = [];

            foreach ($callSites as $site) {
                if (! array_key_exists($varName, $site)) {
                    continue;
                }
                if ($site[$varName]['model'] !== null) {
                    $models[] = $site[$varName]['model'];
                }
                $withLists[] = $site[$varName]['with'];
            }

            $uniqueModels = array_values(array_unique($models));
            $agreedModel = count($uniqueModels) === 1 ? $uniqueModels[0] : null;

            $guaranteedWith = count($withLists) === 1
                ? $withLists[0]
                : array_values(array_intersect(...$withLists));

            if ($agreedModel !== null || $guaranteedWith !== []) {
                $aggregated[$varName] = [
                    'model' => $agreedModel,
                    'with' => $guaranteedWith,
                ];
            }
        }

        return $aggregated;
    }

    /**
     * @param  array<Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function recordViewCall(array $args, ScopeContext $scope): void
    {
        $first = $args[0] ?? null;
        if (! $first instanceof Node\Arg || ! $first->value instanceof Node\Scalar\String_) {
            return;
        }

        $viewName = str_replace('/', '.', $first->value->value);
        $second = $args[1] ?? null;

        $callSiteVars = [];
        if ($second instanceof Node\Arg) {
            $dataExpr = $second->value;
            if ($dataExpr instanceof Node\Expr\Array_) {
                foreach ($dataExpr->items as $item) {
                    if ($item?->key instanceof Node\Scalar\String_) {
                        $key = $item->key->value;
                        $callSiteVars[$key] = $scope->inspectCollectionExpression($item->value);
                    }
                }
            } elseif ($dataExpr instanceof Node\Expr\FuncCall
                && $dataExpr->name instanceof Node\Name
                && $dataExpr->name->toString() === 'compact'
            ) {
                foreach ($dataExpr->args as $arg) {
                    if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
                        $varName = $arg->value->value;
                        $callSiteVars[$varName] = [
                            'model' => $scope->getVariableModel($varName),
                            'with' => $scope->getEagerLoads($varName),
                        ];
                    }
                }
            }
        }

        $this->viewCallSites[$viewName][] = $callSiteVars;
    }
}
