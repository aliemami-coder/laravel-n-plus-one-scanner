<?php

namespace NPlusOneScanner\Analysis;

final readonly class VariableState
{
    /**
     * @param  list<string>  $eagerLoads
     */
    public function __construct(
        public ?string $model = null,
        public array $eagerLoads = [],
    ) {}

    /**
     * @param  list<string>  $relations
     */
    public function withAddedEagerLoads(array $relations): self
    {
        return new self(
            model: $this->model,
            eagerLoads: array_values(array_unique([...$this->eagerLoads, ...$relations])),
        );
    }

    public function hasEagerLoad(string $relation): bool
    {
        foreach ($this->eagerLoads as $loaded) {
            if ($loaded === $relation || str_starts_with($loaded, $relation.'.')) {
                return true;
            }
        }

        return false;
    }
}
