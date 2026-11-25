<?php

namespace NPlusOneScanner\Support;

use NPlusOneScanner\Data\Issue;

final class BaselineManager
{
    /**
     * @return array<string, true> Map of fingerprint => true
     */
    public function load(string $baselinePath): array
    {
        if (! is_file($baselinePath)) {
            return [];
        }

        $raw = @file_get_contents($baselinePath);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['fingerprints']) || ! is_array($decoded['fingerprints'])) {
            return [];
        }

        $map = [];
        foreach ($decoded['fingerprints'] as $fp) {
            if (is_string($fp)) {
                $map[$fp] = true;
            }
        }

        return $map;
    }

    /**
     * @param  list<Issue>  $issues
     */
    public function save(string $baselinePath, array $issues): void
    {
        $payload = [
            'generated_at' => date('c'),
            'count' => count($issues),
            'fingerprints' => array_values(array_unique(array_map(
                static fn (Issue $issue): string => $issue->fingerprint(),
                $issues
            ))),
        ];

        $dir = dirname($baselinePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $baselinePath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
    }
}
