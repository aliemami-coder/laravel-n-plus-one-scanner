<?php

namespace NPlusOneScanner\Data;

final readonly class ScanResult
{
    /**
     * @param  list<Issue>  $issues
     */
    public function __construct(
        public array $issues,
        public int $filesScanned,
        public int $modelsDiscovered,
        public int $ignoredCount = 0,
        public int $baselinedCount = 0,
        public float $durationSeconds = 0.0,
    ) {}

    public function hasIssues(): bool
    {
        return count($this->issues) > 0;
    }

    public function countBySeverity(string $severity): int
    {
        return count(array_filter(
            $this->issues,
            static fn (Issue $issue): bool => $issue->severity === $severity
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => [
                'files_scanned' => $this->filesScanned,
                'models_discovered' => $this->modelsDiscovered,
                'total_issues' => count($this->issues),
                'high_severity' => $this->countBySeverity(Issue::SEVERITY_HIGH),
                'medium_severity' => $this->countBySeverity(Issue::SEVERITY_MEDIUM),
                'ignored_count' => $this->ignoredCount,
                'baselined_count' => $this->baselinedCount,
                'duration_seconds' => round($this->durationSeconds, 3),
            ],
            'issues' => array_map(
                static fn (Issue $issue): array => $issue->toArray(),
                $this->issues
            ),
        ];
    }
}
