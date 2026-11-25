<?php

namespace NPlusOneScanner;

use NPlusOneScanner\Analyzers\ApiResourceAnalyzer;
use NPlusOneScanner\Analyzers\BladeViewAnalyzer;
use NPlusOneScanner\Analyzers\DirectQueryInLoopAnalyzer;
use NPlusOneScanner\Analyzers\PhpLoopAnalyzer;
use NPlusOneScanner\Analyzers\RelationshipQueryChainAnalyzer;
use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Data\ParsedFile;
use NPlusOneScanner\Data\ScanResult;
use NPlusOneScanner\Discovery\ControllerViewMap;
use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Support\BaselineManager;
use NPlusOneScanner\Support\IgnoreFilter;
use NPlusOneScanner\Support\ParsedFileCache;
use NPlusOneScanner\Support\PhpParserHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class Scanner
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function scan(string $basePath, array $options = []): ScanResult
    {
        $start = microtime(true);
        $basePath = rtrim($basePath, '/\\');

        $parserHelper = new PhpParserHelper;
        $parsedFileCache = ($options['parsed_file_cache'] ?? null) instanceof ParsedFileCache
            ? $options['parsed_file_cache']
            : new ParsedFileCache($parserHelper);

        $modelRegistry = new ModelRegistry($parserHelper, $parsedFileCache);

        $modelDirs = $this->resolvePaths($basePath, $this->config['model_paths'] ?? ['app/Models']);
        $appPaths = $this->resolvePaths($basePath, ['app', 'routes']);
        $modelRegistry->discover($modelDirs, $this->resolvePaths($basePath, ['app']));

        $controllerViewMap = new ControllerViewMap($modelRegistry, $parserHelper, $parsedFileCache);
        $controllerViewMap->discover($appPaths);

        $analyzersConfig = $this->config['analyzers'] ?? [];
        $enablePhpLoops = ($analyzersConfig['php_loops'] ?? true);
        $enableRelationshipChains = ($analyzersConfig['relationship_chains'] ?? true);
        $enableQueriesInLoops = ($analyzersConfig['queries_in_loops'] ?? true);
        $enableApiResources = ($analyzersConfig['api_resources'] ?? true) && ! ($options['no_resources'] ?? false);
        $enableBlade = ($analyzersConfig['blade_views'] ?? true) && ! ($options['no_blade'] ?? false);

        $phpAnalyzers = [];
        if ($enablePhpLoops) {
            $phpAnalyzers[] = new PhpLoopAnalyzer($modelRegistry, $parserHelper, $parsedFileCache);
        }
        if ($enableRelationshipChains) {
            $phpAnalyzers[] = new RelationshipQueryChainAnalyzer($modelRegistry, $parserHelper, $parsedFileCache);
        }
        if ($enableQueriesInLoops) {
            $phpAnalyzers[] = new DirectQueryInLoopAnalyzer($modelRegistry, $parserHelper, $parsedFileCache);
        }
        if ($enableApiResources) {
            $phpAnalyzers[] = new ApiResourceAnalyzer($modelRegistry, $parserHelper, $parsedFileCache);
        }

        $bladeAnalyzer = $enableBlade
            ? new BladeViewAnalyzer($modelRegistry, $controllerViewMap, $parserHelper)
            : null;

        $scanTargets = isset($options['target_path']) && is_string($options['target_path'])
            ? [$this->resolveSinglePath($basePath, $options['target_path'])]
            : $this->resolvePaths($basePath, $this->config['scan_paths'] ?? ['app', 'routes', 'resources/views']);

        $excludePaths = $this->config['exclude_paths'] ?? ['vendor', 'storage', 'bootstrap/cache', 'node_modules', 'tests'];
        $files = $this->collectFiles($scanTargets, $excludePaths);

        $rawIssues = [];
        foreach ($files as $filePath) {
            $relativeFile = $this->makeRelativePath($basePath, $filePath);

            if (str_ends_with($filePath, '.blade.php')) {
                if ($bladeAnalyzer !== null) {
                    $content = @file_get_contents($filePath);
                    if ($content === false) {
                        continue;
                    }
                    foreach ($bladeAnalyzer->analyze($relativeFile, $content) as $issue) {
                        $rawIssues[] = $this->attachAbsoluteFileForIgnore($issue, $filePath);
                    }
                }

                continue;
            }

            if (str_ends_with($filePath, '.php')) {
                $parsed = $parsedFileCache->get($filePath);
                if ($parsed === null) {
                    continue;
                }
                $relativeParsed = new ParsedFile($relativeFile, $parsed->content, $parsed->hash, $parsed->ast);
                foreach ($phpAnalyzers as $analyzer) {
                    foreach ($analyzer->analyzeParsed($relativeParsed) as $issue) {
                        $rawIssues[] = $this->attachAbsoluteFileForIgnore($issue, $filePath);
                    }
                }
            }
        }

        // Deduplicate & sort by file + line
        $rawIssues = $this->deduplicateAndSort($rawIssues);

        // Apply IgnoreFilter (using absolute path lookup or relative path resolved against basePath)
        $ignoreFilter = new IgnoreFilter($this->config['ignore_relations'] ?? []);
        $afterIgnore = [];
        $ignoredCount = 0;

        foreach ($rawIssues as [$issue, $absPath]) {
            $checkIssue = new Issue(
                file: $absPath,
                line: $issue->line,
                type: $issue->type,
                severity: $issue->severity,
                model: $issue->model,
                relation: $issue->relation,
                message: $issue->message,
                suggestion: $issue->suggestion,
                snippet: $issue->snippet,
            );

            if ($ignoreFilter->shouldIgnore($checkIssue)) {
                $ignoredCount++;

                continue;
            }

            $afterIgnore[] = $issue;
        }

        // Apply BaselineFilter (unless generating baseline)
        $baselinedCount = 0;
        $finalIssues = $afterIgnore;

        $baselinePath = $options['baseline_path'] ?? null;
        if (is_string($baselinePath) && $baselinePath !== '' && ! ($options['generate_baseline'] ?? false)) {
            $resolvedBaseline = $this->resolveSinglePath($basePath, $baselinePath);
            $baselineMap = (new BaselineManager)->load($resolvedBaseline);
            $finalIssues = [];
            foreach ($afterIgnore as $issue) {
                if (isset($baselineMap[$issue->fingerprint()])) {
                    $baselinedCount++;

                    continue;
                }
                $finalIssues[] = $issue;
            }
        }

        // Severity filter
        $severityFilter = $options['severity'] ?? 'all';
        if ($severityFilter === Issue::SEVERITY_HIGH) {
            $finalIssues = array_values(array_filter(
                $finalIssues,
                static fn (Issue $i): bool => $i->severity === Issue::SEVERITY_HIGH
            ));
        } elseif ($severityFilter === Issue::SEVERITY_MEDIUM) {
            $finalIssues = array_values(array_filter(
                $finalIssues,
                static fn (Issue $i): bool => $i->severity === Issue::SEVERITY_MEDIUM
            ));
        }

        return new ScanResult(
            issues: $finalIssues,
            filesScanned: count($files),
            modelsDiscovered: $modelRegistry->count(),
            ignoredCount: $ignoredCount,
            baselinedCount: $baselinedCount,
            durationSeconds: microtime(true) - $start,
        );
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function resolvePaths(string $basePath, array $paths): array
    {
        return array_map(
            fn (string $p): string => $this->resolveSinglePath($basePath, $p),
            $paths
        );
    }

    private function resolveSinglePath(string $basePath, string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }

        return $basePath.DIRECTORY_SEPARATOR.ltrim($path, '/\\');
    }

    /**
     * @param  list<string>  $targets
     * @param  list<string>  $excludePaths
     * @return list<string>
     */
    private function collectFiles(array $targets, array $excludePaths): array
    {
        $files = [];

        foreach ($targets as $target) {
            if (is_file($target)) {
                $files[] = $target;

                continue;
            }

            if (! is_dir($target)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();
                if ($this->isExcluded($path, $excludePaths)) {
                    continue;
                }

                $files[] = $path;
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * @param  list<string>  $excludePaths
     */
    private function isExcluded(string $path, array $excludePaths): bool
    {
        $normalized = str_replace('\\', '/', $path);
        foreach ($excludePaths as $exclude) {
            $needle = trim(str_replace('\\', '/', $exclude), '/');
            if ($needle !== '' && (str_contains($normalized, '/'.$needle.'/') || str_ends_with($normalized, '/'.$needle))) {
                return true;
            }
        }

        return false;
    }

    private function makeRelativePath(string $basePath, string $filePath): string
    {
        $normBase = rtrim(str_replace('\\', '/', $basePath), '/').'/';
        $normFile = str_replace('\\', '/', $filePath);

        if (str_starts_with($normFile, $normBase)) {
            return substr($normFile, strlen($normBase));
        }

        return $normFile;
    }

    /**
     * @return array{0: Issue, 1: string}
     */
    private function attachAbsoluteFileForIgnore(Issue $issue, string $absPath): array
    {
        return [$issue, $absPath];
    }

    /**
     * @param  list<array{0: Issue, 1: string}>  $items
     * @return list<array{0: Issue, 1: string}>
     */
    private function deduplicateAndSort(array $items): array
    {
        $seen = [];
        $unique = [];

        foreach ($items as [$issue, $absPath]) {
            $key = $issue->file.':'.$issue->line.':'.$issue->type.':'.($issue->relation ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = [$issue, $absPath];
        }

        usort($unique, static function (array $a, array $b): int {
            $cmp = strcmp($a[0]->file, $b[0]->file);

            return $cmp !== 0 ? $cmp : ($a[0]->line <=> $b[0]->line);
        });

        return $unique;
    }
}
