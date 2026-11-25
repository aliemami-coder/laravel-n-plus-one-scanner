<?php

namespace NPlusOneScanner\Reporting;

use NPlusOneScanner\Data\ScanResult;

final class JsonReporter
{
    public function render(ScanResult $result): string
    {
        return (string) json_encode(
            $result->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
