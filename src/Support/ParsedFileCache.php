<?php

namespace NPlusOneScanner\Support;

use NPlusOneScanner\Data\ParsedFile;

final class ParsedFileCache
{
    /** @var array<string, ParsedFile> */
    private array $cache = [];

    private int $parseCount = 0;

    private int $cacheHits = 0;

    public function __construct(
        private readonly PhpParserHelper $parserHelper = new PhpParserHelper,
    ) {}

    public function get(string $filePath): ?ParsedFile
    {
        $key = $this->normalizePath($filePath);

        if (isset($this->cache[$key])) {
            $this->cacheHits++;

            return $this->cache[$key];
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $this->parseCount++;
        $parsed = new ParsedFile(
            path: $filePath,
            content: $content,
            hash: sha1($content),
            ast: $this->parserHelper->parse($content),
        );

        $this->cache[$key] = $parsed;

        return $parsed;
    }

    public function fromContent(string $filePath, string $content): ParsedFile
    {
        $hash = sha1($content);
        $key = $this->normalizePath($filePath).':'.$hash;

        if (isset($this->cache[$key])) {
            $this->cacheHits++;

            return $this->cache[$key];
        }

        $this->parseCount++;
        $parsed = new ParsedFile(
            path: $filePath,
            content: $content,
            hash: $hash,
            ast: $this->parserHelper->parse($content),
        );

        $this->cache[$key] = $parsed;

        return $parsed;
    }

    public function getParseCount(): int
    {
        return $this->parseCount;
    }

    public function getCacheHits(): int
    {
        return $this->cacheHits;
    }

    public function count(): int
    {
        return count($this->cache);
    }

    public function clear(): void
    {
        $this->cache = [];
        $this->parseCount = 0;
        $this->cacheHits = 0;
    }

    private function normalizePath(string $filePath): string
    {
        $real = @realpath($filePath);

        return str_replace('\\', '/', $real !== false ? $real : $filePath);
    }
}
