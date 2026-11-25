<?php

namespace NPlusOneScanner\Support;

use NPlusOneScanner\Data\Issue;

final class IgnoreFilter
{
    /** @var array<string, list<string>> Cache of file lines */
    private array $fileLinesCache = [];

    /**
     * @param  list<string>  $ignoredRelations  e.g. ['roles', 'User@posts']
     */
    public function __construct(
        private readonly array $ignoredRelations = [],
    ) {}

    public function shouldIgnore(Issue $issue): bool
    {
        if ($this->isRelationIgnored($issue)) {
            return true;
        }

        return $this->hasInlineIgnoreComment($issue->file, $issue->line);
    }

    private function isRelationIgnored(Issue $issue): bool
    {
        if ($issue->relation === null) {
            return false;
        }

        if (in_array($issue->relation, $this->ignoredRelations, true)) {
            return true;
        }

        if ($issue->model !== null) {
            $scoped = $issue->model.'@'.$issue->relation;
            if (in_array($scoped, $this->ignoredRelations, true)) {
                return true;
            }
        }

        return false;
    }

    private function hasInlineIgnoreComment(string $filePath, int $line): bool
    {
        if (! isset($this->fileLinesCache[$filePath])) {
            $content = @file_get_contents($filePath);
            $this->fileLinesCache[$filePath] = $content !== false
                ? (preg_split('/\R/', $content) ?: [])
                : [];
        }

        $lines = $this->fileLinesCache[$filePath];
        $currentLine = $lines[$line - 1] ?? '';
        $prevLine = $lines[$line - 2] ?? '';

        return str_contains($currentLine, '@nplusone-ignore')
            || str_contains($prevLine, '@nplusone-ignore');
    }
}
