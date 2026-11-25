<?php

namespace NPlusOneScanner\Discovery;

use NPlusOneScanner\Data\ModelDefinition;
use NPlusOneScanner\Support\ParsedFileCache;
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

    /** @var array<string, array{relations: array<string, ?string>, defaultWith: list<string>, traits: list<string>}> */
    private array $traitDefinitions = [];

    /** @var array<string, array{parent: ?string, relations: array<string, ?string>, defaultWith: list<string>, traits: list<string>, file: string, shortName: string, isModel: bool}> */
    private array $parsedClasses = [];

    public function __construct(
        private readonly PhpParserHelper $parserHelper = new PhpParserHelper,
        private readonly ParsedFileCache $parsedFileCache = new ParsedFileCache,
    ) {}

    /**
     * @param  list<string>  $modelDirectories
     * @param  list<string>  $supportDirectories  Optional extra directories (e.g. app/) to index traits & base models
     */
    public function discover(array $modelDirectories, array $supportDirectories = []): void
    {
        foreach ($supportDirectories as $directory) {
            $this->scanDirectory($directory, registerAsModelCandidate: false);
        }

        foreach ($modelDirectories as $directory) {
            $this->scanDirectory($directory, registerAsModelCandidate: true);
        }

        $this->resolveInheritanceAndTraits();
    }

    public function registerFromFile(string $filePath, bool $registerAsModelCandidate = true, bool $resolve = true): void
    {
        $parsed = $this->parsedFileCache->get($filePath);
        if ($parsed === null || $parsed->ast === null) {
            return;
        }

        $ast = $parsed->ast;

        $nodeFinder = new NodeFinder;

        /** @var list<Node\Stmt\Trait_> $traits */
        $traits = $nodeFinder->findInstanceOf($ast, Node\Stmt\Trait_::class);
        foreach ($traits as $trait) {
            if ($trait->name === null) {
                continue;
            }
            $fqn = ltrim($trait->namespacedName?->toString() ?? $trait->name->toString(), '\\');
            $this->traitDefinitions[$fqn] = [
                'relations' => $this->extractRelations($trait),
                'defaultWith' => $this->extractDefaultWith($trait),
                'traits' => $this->extractUsedTraits($trait),
            ];
        }

        /** @var list<Node\Stmt\Class_> $classes */
        $classes = $nodeFinder->findInstanceOf($ast, Node\Stmt\Class_::class);
        foreach ($classes as $class) {
            if ($class->name === null) {
                continue;
            }

            $fqn = ltrim($class->namespacedName?->toString() ?? $class->name->toString(), '\\');
            $parentFqn = $class->extends !== null
                ? ltrim(PhpParserHelper::resolveClassName($class->extends) ?? '', '\\')
                : null;

            $isModel = $registerAsModelCandidate && $this->looksLikeModel($class);
            $this->parsedClasses[$fqn] = [
                'parent' => $parentFqn !== '' ? $parentFqn : null,
                'relations' => $this->extractRelations($class),
                'defaultWith' => $this->extractDefaultWith($class),
                'traits' => $this->extractUsedTraits($class),
                'file' => $filePath,
                'shortName' => $class->name->toString(),
                'isModel' => $isModel,
            ];
        }

        if ($resolve && $registerAsModelCandidate) {
            $this->resolveInheritanceAndTraits();
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

        // Return null when ambiguous (multiple models share the same short name).
        return count($matches) === 1 ? $matches[0] : null;
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

    private function scanDirectory(string $directory, bool $registerAsModelCandidate): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->registerFromFile($file->getPathname(), $registerAsModelCandidate, resolve: false);
            }
        }
    }

    private function resolveInheritanceAndTraits(): void
    {
        foreach ($this->parsedClasses as $fqn => $info) {
            if (! $info['isModel'] && ! $this->inheritsFromBaseModel($fqn)) {
                continue;
            }

            if (isset($this->modelsByFqn[$fqn])) {
                continue;
            }

            $relations = [];
            $defaultWith = [];
            $visited = [];
            $this->collectInheritedMembers($fqn, $relations, $defaultWith, $visited);

            $this->register(new ModelDefinition(
                fqn: $fqn,
                shortName: $info['shortName'],
                file: $info['file'],
                relations: $relations,
                defaultWith: array_values(array_unique($defaultWith)),
            ));
        }
    }

    private function inheritsFromBaseModel(string $fqn): bool
    {
        $visited = [];
        $current = $fqn;

        while ($current !== null && ! isset($visited[$current])) {
            $visited[$current] = true;
            $parent = $this->parsedClasses[$current]['parent'] ?? null;
            if ($parent === null) {
                return false;
            }
            if (in_array($parent, self::BASE_MODEL_CLASSES, true)) {
                return true;
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * @param  array<string, ?string>  $relations
     * @param  list<string>  $defaultWith
     * @param  array<string, true>  $visited
     */
    private function collectInheritedMembers(string $fqn, array &$relations, array &$defaultWith, array &$visited): void
    {
        if (isset($visited[$fqn]) || ! isset($this->parsedClasses[$fqn])) {
            return;
        }
        $visited[$fqn] = true;

        $classInfo = $this->parsedClasses[$fqn];
        if ($classInfo['parent'] !== null) {
            $this->collectInheritedMembers($classInfo['parent'], $relations, $defaultWith, $visited);
        }

        $traitVisited = [];
        foreach ($classInfo['traits'] as $traitFqn) {
            $this->collectTraitMembers($traitFqn, $relations, $defaultWith, $traitVisited);
        }

        foreach ($classInfo['relations'] as $relName => $related) {
            $relations[$relName] = $related;
        }
        $defaultWith = [...$defaultWith, ...$classInfo['defaultWith']];
    }

    /**
     * @param  array<string, ?string>  $relations
     * @param  list<string>  $defaultWith
     * @param  array<string, true>  $visited
     */
    private function collectTraitMembers(string $traitFqn, array &$relations, array &$defaultWith, array &$visited): void
    {
        if (isset($visited[$traitFqn])) {
            return;
        }
        $visited[$traitFqn] = true;

        $traitInfo = $this->traitDefinitions[$traitFqn] ?? null;
        if ($traitInfo === null) {
            // Fallback to short-name match if namespace differed
            $short = PhpParserHelper::shortName($traitFqn);
            foreach ($this->traitDefinitions as $candidateFqn => $candidate) {
                if (PhpParserHelper::shortName($candidateFqn) === $short) {
                    $traitInfo = $candidate;
                    break;
                }
            }
        }

        if ($traitInfo === null) {
            return;
        }

        foreach ($traitInfo['traits'] as $nestedTrait) {
            $this->collectTraitMembers($nestedTrait, $relations, $defaultWith, $visited);
        }

        foreach ($traitInfo['relations'] as $relName => $related) {
            $relations[$relName] = $related;
        }
        $defaultWith = [...$defaultWith, ...$traitInfo['defaultWith']];
    }

    private function looksLikeModel(Node\Stmt\Class_ $class): bool
    {
        if ($class->extends === null) {
            return false;
        }

        $parentFqn = ltrim(PhpParserHelper::resolveClassName($class->extends) ?? '', '\\');
        if (in_array($parentFqn, self::BASE_MODEL_CLASSES, true)) {
            return true;
        }

        if (str_ends_with($parentFqn, '\\Model') || str_ends_with($parentFqn, 'BaseModel')) {
            return true;
        }

        return count($this->extractRelations($class)) > 0;
    }

    /**
     * @return list<string>
     */
    private function extractUsedTraits(Node\Stmt\ClassLike $classLike): array
    {
        $traits = [];
        foreach ($classLike->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\TraitUse) {
                continue;
            }
            foreach ($stmt->traits as $traitName) {
                $resolved = PhpParserHelper::resolveClassName($traitName);
                if ($resolved !== null) {
                    $traits[] = ltrim($resolved, '\\');
                }
            }
        }

        return $traits;
    }

    /**
     * @return array<string, string|null>
     */
    private function extractRelations(Node\Stmt\ClassLike $classLike): array
    {
        $relations = [];
        $nodeFinder = new NodeFinder;

        foreach ($classLike->getMethods() as $method) {
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

            if ($callName === 'morphTo') {
                return null;
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

            return $resolved !== null ? ltrim($resolved, '\\') : null;
        }

        if ($firstArg->value instanceof Node\Scalar\String_) {
            return ltrim($firstArg->value->value, '\\');
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
    private function extractDefaultWith(Node\Stmt\ClassLike $classLike): array
    {
        $defaultWith = [];

        foreach ($classLike->getProperties() as $property) {
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
