<?php

namespace NPlusOneScanner\Console;

use Illuminate\Console\Command;
use NPlusOneScanner\Reporting\ConsoleReporter;
use NPlusOneScanner\Reporting\HtmlReporter;
use NPlusOneScanner\Reporting\JsonReporter;
use NPlusOneScanner\Scanner;
use NPlusOneScanner\Support\BaselineManager;

class ScanCommand extends Command
{
    protected $signature = 'nplusone:scan
        {path? : Optional relative or absolute path to scan}
        {--base-path= : Override the project base path}
        {--format=table : Output format (table, json, or html)}
        {--output= : File path to write the report (defaults to nplusone-report.html for html format; use "stdout" to print)}
        {--severity=all : Filter by severity (all, high, medium)}
        {--baseline= : Path to baseline JSON file}
        {--generate-baseline : Save current issues to the baseline file}
        {--no-blade : Skip Blade template analysis}
        {--no-resources : Skip API Resource analysis}
        {--no-fail : Always exit with code 0 even if issues are found}';

    protected $description = 'Statically scan the Laravel project for N+1 query issues';

    public function handle(Scanner $scanner): int
    {
        $basePath = $this->option('base-path') ?: base_path();
        $format = strtolower((string) $this->option('format'));
        $generateBaseline = (bool) $this->option('generate-baseline');

        $baselinePath = $this->option('baseline');
        if ($baselinePath === null) {
            $defaultBaseline = config('nplusone.baseline_path', 'nplusone-baseline.json');
            $resolvedDefault = $basePath.DIRECTORY_SEPARATOR.$defaultBaseline;
            if ($generateBaseline || is_file($resolvedDefault)) {
                $baselinePath = $defaultBaseline;
            }
        }

        $result = $scanner->scan($basePath, [
            'target_path' => $this->argument('path'),
            'severity' => (string) $this->option('severity'),
            'baseline_path' => $baselinePath,
            'generate_baseline' => $generateBaseline,
            'no_blade' => (bool) $this->option('no-blade'),
            'no_resources' => (bool) $this->option('no-resources'),
        ]);

        if ($generateBaseline && is_string($baselinePath) && $baselinePath !== '') {
            $resolved = str_starts_with($baselinePath, '/')
                ? $baselinePath
                : $basePath.DIRECTORY_SEPARATOR.$baselinePath;
            (new BaselineManager)->save($resolved, $result->issues);
            $this->info(sprintf('Baseline generated at %s with %d issue(s).', $resolved, count($result->issues)));

            return self::SUCCESS;
        }

        $outputOption = $this->option('output');

        if ($format === 'html') {
            $html = (new HtmlReporter)->render($result);
            if ($outputOption === 'stdout') {
                $this->line($html);
            } else {
                $targetFile = is_string($outputOption) && $outputOption !== ''
                    ? $outputOption
                    : 'nplusone-report.html';
                $resolvedOutput = str_starts_with($targetFile, '/')
                    ? $targetFile
                    : $basePath.DIRECTORY_SEPARATOR.$targetFile;
                $dir = dirname($resolvedOutput);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($resolvedOutput, $html);
                $this->info(sprintf('HTML report generated at %s (%d issue(s)).', $resolvedOutput, count($result->issues)));
            }
        } elseif ($format === 'json') {
            $json = (new JsonReporter)->render($result);
            if (is_string($outputOption) && $outputOption !== '' && $outputOption !== 'stdout') {
                $resolvedOutput = str_starts_with($outputOption, '/')
                    ? $outputOption
                    : $basePath.DIRECTORY_SEPARATOR.$outputOption;
                $dir = dirname($resolvedOutput);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($resolvedOutput, $json.PHP_EOL);
                $this->info(sprintf('JSON report generated at %s (%d issue(s)).', $resolvedOutput, count($result->issues)));
            } else {
                $this->line($json);
            }
        } else {
            (new ConsoleReporter)->render($this, $result);
        }

        $shouldFail = ! (bool) $this->option('no-fail') && (bool) config('nplusone.fail_on_issues', true);

        return ($shouldFail && $result->hasIssues()) ? self::FAILURE : self::SUCCESS;
    }
}
