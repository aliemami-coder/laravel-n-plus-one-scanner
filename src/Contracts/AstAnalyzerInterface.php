<?php

namespace NPlusOneScanner\Contracts;

use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Data\ParsedFile;

interface AstAnalyzerInterface extends AnalyzerInterface
{
    /**
     * @return list<Issue>
     */
    public function analyzeParsed(ParsedFile $parsedFile): array;
}
