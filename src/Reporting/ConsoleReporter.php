<?php

namespace NPlusOneScanner\Reporting;

use Illuminate\Console\Command;
use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Data\ScanResult;

final class ConsoleReporter
{
    public function render(Command $command, ScanResult $result): void
    {
        $command->newLine();
        $command->line(sprintf(
            '<info>Scanned %d files</info> | <info>%d models discovered</info> | <comment>%.2fs</comment>',
            $result->filesScanned,
            $result->modelsDiscovered,
            $result->durationSeconds
        ));

        if ($result->ignoredCount > 0 || $result->baselinedCount > 0) {
            $command->line(sprintf(
                '<fg=gray>Suppressed: %d inline/config ignored, %d baselined</>',
                $result->ignoredCount,
                $result->baselinedCount
            ));
        }

        $command->newLine();

        if (! $result->hasIssues()) {
            $command->info('No N+1 query issues found!');

            return;
        }

        $rows = [];
        foreach ($result->issues as $issue) {
            $severityLabel = $issue->severity === Issue::SEVERITY_HIGH
                ? '<fg=red;options=bold>HIGH</>'
                : '<fg=yellow>MEDIUM</>';

            $rows[] = [
                "{$issue->file}:{$issue->line}",
                $severityLabel,
                $issue->type,
                $issue->message."\n<fg=green>Fix:</> ".$issue->suggestion,
            ];
        }

        $command->table(
            ['Location', 'Severity', 'Pattern', 'Details & Suggestion'],
            $rows
        );

        $command->newLine();
        $command->error(sprintf(
            'Found %d potential N+1 issue(s) (%d high, %d medium).',
            count($result->issues),
            $result->countBySeverity(Issue::SEVERITY_HIGH),
            $result->countBySeverity(Issue::SEVERITY_MEDIUM),
        ));
    }
}
