<?php

namespace NPlusOneScanner\Data;

final class ModelDefinition
{
    /**
     * @param  array<string, string|null>  $relations  Map of relationName => relatedModelShortName|null
     * @param  list<string>  $defaultWith  Relations listed in $with property
     */
    public function __construct(
        public readonly string $fqn,
        public readonly string $shortName,
        public readonly string $file,
        public array $relations = [],
        public array $defaultWith = [],
    ) {}

    public function hasRelation(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    public function isDefaultEagerLoaded(string $name): bool
    {
        return in_array($name, $this->defaultWith, true);
    }

    public function getRelatedModel(string $relation): ?string
    {
        return $this->relations[$relation] ?? null;
    }
}
