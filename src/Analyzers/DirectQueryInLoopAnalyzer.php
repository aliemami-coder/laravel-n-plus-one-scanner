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

final class DirectQueryInLoopAnalyzer implements AstAnalyzerInterface
{
    private const DIRECT_STATIC_QUERY_METHODS = [
        'find',
        'findOrFail',
        'findMany',
        'findOrNew',
        'firstWhere',
    ];

    private const CHAINED_TERMINAL_METHODS = [
        'get',
        'first',
        'firstOrFail',
        'exists',
        'doesntExist',
        'count',
        'pluck',
        'value',
        'sum',
        'avg',
    ];

    private const COLLECTION_LOOP_METHODS = [
        'each',
        'map',
        'filter',
        'flatMap',
        'transform',
        'reject',
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
        $reportedLines = [];
        $nodeFinder = new NodeFinder;

        $loopBodies = $this->collectLoopBodies($ast, $nodeFinder);

        foreach ($loopBodies as $bodyNodes) {
            // 1. Direct static Model::find(...) / Model::firstWhere(...)
            /** @var list<Node\Expr\StaticCall> $staticCalls */
            $staticCalls = $nodeFinder->findInstanceOf($bodyNodes, Node\Expr\StaticCall::class);
            foreach ($staticCalls as $call) {
                if (! $call->name instanceof Node\Identifier) {
                    continue;
                }

                $className = PhpParserHelper::resolveClassName($call->class);
                $methodName = $call->name->toString();
                $line = $call->getStartLine();

                if ($className !== null
                    && PhpParserHelper::shortName($className) === 'DB'
                    && in_array($methodName, ['select', 'selectOne', 'statement'], true)
                ) {
                    $this->addIssue($issues, $reportedLines, $filePath, $line, 'DB', "DB::{$methodName}()", $content);

                    continue;
                }

                if ($className !== null
                    && $this->modelRegistry->isKnownModelClass($className)
                    && in_array($methodName, self::DIRECT_STATIC_QUERY_METHODS, true)
                ) {
                    $short = PhpParserHelper::shortName($className);
                    $this->addIssue($issues, $reportedLines, $filePath, $line, $short, "{$short}::{$methodName}()", $content);
                }
            }

            // 2. Chained Model::where(...)->first() or DB::table(...)->get()
            /** @var list<Node\Expr\MethodCall> $methodCalls */
            $methodCalls = $nodeFinder->findInstanceOf($bodyNodes, Node\Expr\MethodCall::class);
            foreach ($methodCalls as $call) {
                if (! $call->name instanceof Node\Identifier) {
                    continue;
                }

                $terminal = $call->name->toString();
                if (! in_array($terminal, self::CHAINED_TERMINAL_METHODS, true)) {
                    continue;
                }

                $rootStatic = $this->findRootStaticCall($call);
                if ($rootStatic === null || ! $rootStatic->name instanceof Node\Identifier) {
                    continue;
                }

                $className = PhpParserHelper::resolveClassName($rootStatic->class);
                if ($className === null) {
                    continue;
                }

                $short = PhpParserHelper::shortName($className);
                $rootMethod = $rootStatic->name->toString();
                $line = $call->getStartLine();

                if ($short === 'DB' && $rootMethod === 'table') {
                    $this->addIssue($issues, $reportedLines, $filePath, $line, 'DB', "DB::table()->...->{$terminal}()", $content);
                } elseif ($this->modelRegistry->isKnownModelClass($className)) {
                    $this->addIssue($issues, $reportedLines, $filePath, $line, $short, "{$short}::{$rootMethod}()->...->{$terminal}()", $content);
                }
            }
        }

        return $issues;
    }

    /**
     * @param  list<Node>  $ast
     * @return list<list<Node>>
     */
    private function collectLoopBodies(array $ast, NodeFinder $nodeFinder): array
    {
        $bodies = [];

        foreach ([Node\Stmt\Foreach_::class, Node\Stmt\For_::class, Node\Stmt\While_::class, Node\Stmt\Do_::class] as $loopClass) {
            /** @var list<Node\Stmt\Foreach_|Node\Stmt\For_|Node\Stmt\While_|Node\Stmt\Do_> $loops */
            $loops = $nodeFinder->findInstanceOf($ast, $loopClass);
            foreach ($loops as $loop) {
                $bodies[] = $loop->stmts;
            }
        }

        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $nodeFinder->findInstanceOf($ast, Node\Expr\MethodCall::class);
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

            if ($firstArg->value instanceof Node\Expr\Closure) {
                $bodies[] = $firstArg->value->stmts;
            } elseif ($firstArg->value instanceof Node\Expr\ArrowFunction) {
                $bodies[] = [$firstArg->value->expr];
            }
        }

        return $bodies;
    }

    private function findRootStaticCall(Node\Expr\MethodCall $call): ?Node\Expr\StaticCall
    {
        $current = $call->var;
        while ($current instanceof Node\Expr\MethodCall) {
            if ($current->name instanceof Node\Identifier
                && in_array($current->name->toString(), ['get', 'first', 'firstOrFail', 'pluck', 'value', 'paginate', 'cursor', 'lazy'], true)
            ) {
                return null;
            }
            $current = $current->var;
        }

        return $current instanceof Node\Expr\StaticCall ? $current : null;
    }

    /**
     * @param  list<Issue>  $issues
     * @param  array<string, true>  $reportedLines
     */
    private function addIssue(
        array &$issues,
        array &$reportedLines,
        string $filePath,
        int $line,
        string $model,
        string $expression,
        string $content,
    ): void {
        $key = $line.':'.$expression;
        if (isset($reportedLines[$key])) {
            return;
        }
        $reportedLines[$key] = true;

        $issues[] = new Issue(
            file: $filePath,
            line: $line,
            type: Issue::TYPE_QUERY_IN_LOOP,
            severity: Issue::SEVERITY_HIGH,
            model: $model,
            relation: null,
            message: "Direct database query `{$expression}` executed inside a loop (N+1 query).",
            suggestion: 'Fetch all required records in a single `whereIn(...)->get()->keyBy(...)` query before the loop, or use an Eloquent relationship with `with()`.',
            snippet: PhpParserHelper::getLineSnippet($content, $line),
        );
    }
}
