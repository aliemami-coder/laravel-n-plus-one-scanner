<?php

namespace NPlusOneScanner\Discovery;

use NPlusOneScanner\Analysis\ScopeContext;
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
    /** @var array<string, array<string, array{model: ?string, with: list<string>}>> */
    private array $viewContexts = [];

    public function __construct(
        private readonly ModelRegistry $modelRegistry,
        private readonly PhpParserHelper $parserHelper = new PhpParserHelper,
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
        $code = @file_get_contents($filePath);
        if ($code === false) {
            return;
        }

        $ast = $this->parserHelper->parse($code);
        if ($ast === null) {
            return;
        }

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
     * @return array<string, array{model: ?string, with: list<string>}>
     */
    public function getContextForView(string $viewName): array
    {
        return $this->viewContexts[$viewName] ?? [];
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
        if (! $second instanceof Node\Arg) {
            return;
        }

        $dataExpr = $second->value;
        if ($dataExpr instanceof Node\Expr\Array_) {
            foreach ($dataExpr->items as $item) {
                if ($item?->key instanceof Node\Scalar\String_) {
                    $key = $item->key->value;
                    $info = $scope->inspectCollectionExpression($item->value);
                    $this->mergeViewVar($viewName, $key, $info);
                }
            }
        } elseif ($dataExpr instanceof Node\Expr\FuncCall
            && $dataExpr->name instanceof Node\Name
            && $dataExpr->name->toString() === 'compact'
        ) {
            foreach ($dataExpr->args as $arg) {
                if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
                    $varName = $arg->value->value;
                    $this->mergeViewVar($viewName, $varName, [
                        'model' => $scope->getVariableModel($varName),
                        'with' => $scope->getEagerLoads($varName),
                    ]);
                }
            }
        }
    }

    /**
     * @param  array{model: ?string, with: list<string>}  $info
     */
    private function mergeViewVar(string $viewName, string $varName, array $info): void
    {
        if ($info['model'] === null && $info['with'] === []) {
            return;
        }

        $existing = $this->viewContexts[$viewName][$varName] ?? ['model' => null, 'with' => []];
        $this->viewContexts[$viewName][$varName] = [
            'model' => $info['model'] ?? $existing['model'],
            'with' => array_values(array_unique([...$existing['with'], ...$info['with']])),
        ];
    }
}
