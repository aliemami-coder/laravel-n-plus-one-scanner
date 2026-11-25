<?php

namespace NPlusOneScanner\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class PhpParserHelper
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * Parse PHP source code and run NameResolver so class names are resolved to FQNs.
     *
     * @return list<Node\Stmt>|null
     */
    public function parse(string $code): ?array
    {
        try {
            $ast = $this->parser->parse($code);
        } catch (Error) {
            return null;
        }

        if ($ast === null) {
            return null;
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));

        /** @var list<Node\Stmt> */
        return $traverser->traverse($ast);
    }

    /**
     * Extract string relation names from ->with('a', 'b') or ->with(['a', 'b' => fn() => ...]).
     *
     * @param  array<Node\Arg|Node\VariadicPlaceholder>  $args
     * @return list<string>
     */
    public static function extractRelationNamesFromArgs(array $args): array
    {
        $relations = [];

        foreach ($args as $arg) {
            if (! $arg instanceof Node\Arg) {
                continue;
            }

            $value = $arg->value;

            if ($value instanceof Node\Scalar\String_) {
                $relations[] = self::stripSelectColumns($value->value);
            } elseif ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $item) {
                    if ($item === null) {
                        continue;
                    }

                    // ['posts' => fn ($q) => ...]
                    if ($item->key instanceof Node\Scalar\String_) {
                        $relations[] = self::stripSelectColumns($item->key->value);
                    } elseif ($item->value instanceof Node\Scalar\String_) {
                        // ['posts', 'comments']
                        $relations[] = self::stripSelectColumns($item->value->value);
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($relations)));
    }

    /**
     * Strip column selection from eager load string, e.g. 'user:id,name' -> 'user'.
     */
    public static function stripSelectColumns(string $relation): string
    {
        $colonPos = strpos($relation, ':');

        return $colonPos === false ? trim($relation) : trim(substr($relation, 0, $colonPos));
    }

    public static function shortName(string $fqn): string
    {
        $fqn = ltrim($fqn, '\\');
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }

    public static function resolveClassName(Node $nameNode): ?string
    {
        if ($nameNode instanceof Node\Name\FullyQualified) {
            return $nameNode->toString();
        }

        if ($nameNode instanceof Node\Name) {
            $resolved = $nameNode->getAttribute('resolvedName');
            if ($resolved instanceof Node\Name) {
                return $resolved->toString();
            }

            return $nameNode->toString();
        }

        return null;
    }

    public static function getLineSnippet(string $code, int $line): string
    {
        $lines = preg_split('/\R/', $code) ?: [];

        return trim($lines[$line - 1] ?? '');
    }
}
