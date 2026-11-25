<?php

namespace NPlusOneScanner\Discovery;

use NPlusOneScanner\Data\ModelDefinition;
use NPlusOneScanner\Support\PhpParserHelper;
use PhpParser\Node;
use PhpParser\NodeFinder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ModelRegistry
{
    private const RELATION_METHODS = [
        'hasOne',
        'hasOneThrough',
        'morphOne',
        'belongsTo',
        'morphTo',
        'hasMany',
        'hasManyThrough',
        'belongsToMany',
        'morphMany',
        'morphToMany',
        'morphedByMany',
    ];

    private const RELATION_RETURN_TYPES = [
        'HasOne',
        'HasOneThrough',
        'MorphOne',
        'BelongsTo',
        'MorphTo',
        'HasMany',
        'HasManyThrough',
        'BelongsToMany',
        'MorphMany',
        'MorphToMany',
        'MorphedByMany',
        'Relation',
    ];

    private const BASE_MODEL_CLASSES = [
        'Illuminate\\Database\\Eloquent\\Model',
        'Illuminate\\Foundation\\Auth\\User',
        'Illuminate\\Database\\Eloquent\\Relations\\Pivot',
        'Illuminate\\Database\\Eloquent\\Relations\\MorphPivot',
    ];

    /** @var array<string, ModelDefinition> Map of FQN => ModelDefinition */
    private array $modelsByFqn = [];

    /** @var array<string, list<ModelDefinition>> Map of shortName => list<ModelDefinition> */
    private array $modelsByShortName = [];

    /** @var array<string, list<ModelDefinition>> Map of relationName => list<ModelDefinition> */
    private array $modelsByRelation = [];

    public function __construct(
        private readonly PhpParserHelper $parserHelper = new PhpParserHelper,
    ) {}

    /**
     * @param  list<string>  $directories
     */
    public function discover(array $directories): void
    {
        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $this->registerFromFile($file->getPathname());
                }
            }
        }
    }

    public function registerFromFile(string $filePath): void
    {
        $code = @file_get_contents($filePath);
        if ($code === false) {
            return;
        }

        $ast = $this->parserHelper->parse($code);
        if ($ast === null) {
            return;
        }

        $nodeFinder = new NodeFinder;
        /** @var list<Node\Stmt\Class_> $classes */
        $classes = $nodeFinder->findInstanceOf($ast, Node\Stmt\Class_::class);

        foreach ($classes as $class) {
            if ($class->name === null || ! $this->looksLikeModel($class)) {
                continue;
            }

            $fqn = $class->namespacedName?->toString() ?? $class->name->toString();
            $shortName = $class->name->toString();

            $relations = $this->extractRelations($class);
            $defaultWith = $this->extractDefaultWith($class);

            $definition = new ModelDefinition(
                fqn: $fqn,
                shortName: $shortName,
                file: $filePath,
                relations: $relations,
                defaultWith: $defaultWith,
            );

            $this->register($definition);
        }
    }

    public function register(ModelDefinition $definition): void
    {
        $this->modelsByFqn[$definition->fqn] = $definition;
        $this->modelsByShortName[$definition->shortName][] = $definition;

        foreach (array_keys($definition->relations) as $relation) {
            $this->modelsByRelation[$relation][] = $definition;
        }
    }

    public function findByClassName(?string $name): ?ModelDefinition
    {
        if ($name === null || $name === '') {
            return null;
        }

        $normalized = ltrim($name, '\\');
        if (isset($this->modelsByFqn[$normalized])) {
            return $this->modelsByFqn[$normalized];
        }

        $short = PhpParserHelper::shortName($normalized);
        $matches = $this->modelsByShortName[$short] ?? [];

        return count($matches) === 1 ? $matches[0] : ($matches[0] ?? null);
    }

    /**
     * @return list<ModelDefinition>
     */
    public function findModelsWithRelation(string $relation): array
    {
        return $this->modelsByRelation[$relation] ?? [];
    }

    public function isKnownModelClass(?string $name): bool
    {
        return $this->findByClassName($name) !== null;
    }

    public function count(): int
    {
        return count($this->modelsByFqn);
    }

    /**
     * @return array<string, ModelDefinition>
     */
    public function all(): array
    {
        return $this->modelsByFqn;
    }

    private function looksLikeModel(Node\Stmt\Class_ $class): bool
    {
        if ($class->extends === null) {
            return false;
        }

        $parentFqn = PhpParserHelper::resolveClassName($class->extends) ?? '';
        if (in_array($parentFqn, self::BASE_MODEL_CLASSES, true)) {
            return true;
        }

        // Also accept classes extending a base Model in the app (e.g., BaseModel) or having relation methods.
        if (str_ends_with($parentFqn, '\\Model') || str_ends_with($parentFqn, 'BaseModel')) {
            return true;
        }

        return count($this->extractRelations($class)) > 0;
    }

    /**
     * @return array<string, string|null>
     */
    private function extractRelations(Node\Stmt\Class_ $class): array
    {
        $relations = [];
        $nodeFinder = new NodeFinder;

        foreach ($class->getMethods() as $method) {
            if (! $method->isPublic() || $method->isStatic()) {
                continue;
            }

            $methodName = $method->name->toString();
            if (str_starts_with($methodName, 'scope') || str_starts_with($methodName, '__')) {
                continue;
            }

            $relatedModel = $this->detectRelatedModelFromMethod($method, $nodeFinder);
            if ($relatedModel !== false) {
                $relations[$methodName] = $relatedModel;
            }
        }

        return $relations;
    }

    /**
     * Returns string (related model short name), null (relation without resolved target, e.g. morphTo),
     * or false if the method is not an Eloquent relationship.
     */
    private function detectRelatedModelFromMethod(Node\Stmt\ClassMethod $method, NodeFinder $nodeFinder): string|null|false
    {
        $hasRelationReturnType = false;
        if ($method->returnType !== null) {
            $returnTypeStr = $this->extractTypeName($method->returnType);
            if ($returnTypeStr !== null && in_array(PhpParserHelper::shortName($returnTypeStr), self::RELATION_RETURN_TYPES, true)) {
                $hasRelationReturnType = true;
            }
        }

        if ($method->stmts === null) {
            return $hasRelationReturnType ? null : false;
        }

        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = $nodeFinder->findInstanceOf($method->stmts, Node\Expr\MethodCall::class);

        foreach ($calls as $call) {
            if (! $call->var instanceof Node\Expr\Variable || $call->var->name !== 'this') {
                continue;
            }

            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $callName = $call->name->toString();
            if (! in_array($callName, self::RELATION_METHODS, true)) {
                continue;
            }

            return $this->extractRelatedModelArg($call);
        }

        return $hasRelationReturnType ? null : false;
    }

    private function extractRelatedModelArg(Node\Expr\MethodCall $call): ?string
    {
        $firstArg = $call->args[0] ?? null;
        if (! $firstArg instanceof Node\Arg) {
            return null;
        }

        if ($firstArg->value instanceof Node\Expr\ClassConstFetch
            && $firstArg->value->name instanceof Node\Identifier
            && $firstArg->value->name->toString() === 'class'
        ) {
            $resolved = PhpParserHelper::resolveClassName($firstArg->value->class);

            return $resolved !== null ? PhpParserHelper::shortName($resolved) : null;
        }

        if ($firstArg->value instanceof Node\Scalar\String_) {
            return PhpParserHelper::shortName($firstArg->value->value);
        }

        return null;
    }

    private function extractTypeName(Node $type): ?string
    {
        if ($type instanceof Node\Name) {
            return PhpParserHelper::resolveClassName($type);
        }

        if ($type instanceof Node\NullableType) {
            return $this->extractTypeName($type->type);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractDefaultWith(Node\Stmt\Class_ $class): array
    {
        $defaultWith = [];

        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() !== 'with' || ! $prop->default instanceof Node\Expr\Array_) {
                    continue;
                }

                foreach ($prop->default->items as $item) {
                    if ($item?->value instanceof Node\Scalar\String_) {
                        $defaultWith[] = PhpParserHelper::stripSelectColumns($item->value->value);
                    }
                }
            }
        }

        return array_values(array_unique($defaultWith));
    }
}
