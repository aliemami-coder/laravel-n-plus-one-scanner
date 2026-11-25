<?php

namespace NPlusOneScanner\Contracts;

use NPlusOneScanner\Data\Issue;

interface AnalyzerInterface
{
    /**
     * @return list<Issue>
     */
    public function analyze(string $filePath, string $content): array;
}
