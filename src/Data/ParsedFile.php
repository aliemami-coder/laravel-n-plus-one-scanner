<?php

namespace NPlusOneScanner\Data;

use PhpParser\Node;

final readonly class ParsedFile
{
    /**
     * @param  list<Node\Stmt>|null  $ast
     */
    public function __construct(
        public string $path,
        public string $content,
        public string $hash,
        public ?array $ast,
    ) {}
}
