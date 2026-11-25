<?php

namespace NPlusOneScanner\Analysis;

use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Support\PhpParserHelper;
use PhpParser\Node;

final class ScopeContext
{
    /** @var array<string, VariableState> Map of variableName => VariableState */
    private array $variables = [];

    public function __construct(
        private readonly ModelRegistry $modelRegistry,
    ) {}

    public function setVariableModel(string $varName, string $modelClass): void
    {
        $existing = $this->variables[$varName] ?? new VariableState;
        $this->variables[$varName] = new VariableState($modelClass, $existing->eagerLoads);
    }

    public function getVariableModel(string $varName): ?string
    {
        return isset($this->variables[$varName]) ? $this->variables[$varName]->model : null;
    }

    /**
     * @param  list<string>  $relations
     */
    public function addEagerLoads(string $varName, array $relations): void
    {
        $existing = $this->variables[$varName] ?? new VariableState;
        $this->variables[$varName] = $existing->withAddedEagerLoads($relations);
    }

    /**
     * @return list<string>
     */
    public function getEagerLoads(string $varName): array
    {
        return isset($this->variables[$varName]) ? $this->variables[$varName]->eagerLoads : [];
    }

    public function getVariableState(string $varName): ?VariableState
    {
        return $this->variables[$varName] ?? null;
    }

    public function setVariableState(string $varName, ?VariableState $state): void
    {
        if ($state === null || ($state->model === null && $state->eagerLoads === [])) {
            unset($this->variables[$varName]);

            return;
        }

        $this->variables[$varName] = $state;
    }

    public function isRelationEagerLoaded(string $varName, string $relation): bool
    {
        $state = $this->variables[$varName] ?? null;
        if ($state !== null && $state->hasEagerLoad($relation)) {
            return true;
        }

        $modelName = $state?->model;
        if ($modelName !== null) {
            $model = $this->modelRegistry->findByClassName($modelName);
            if ($model !== null && $model->isDefaultEagerLoaded($relation)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a statement in sequential order within a function/method body.
     */
    public function processStatement(Node $stmt): void
    {
        if (! $stmt instanceof Node\Stmt\Expression) {
            return;
        }

        $expr = $stmt->expr;

        if ($expr instanceof Node\Expr\Assign && $expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
            $targetVar = $expr->var->name;
            $info = $this->inspectCollectionExpression($expr->expr);

            // Reassignment always replaces any previous state for $targetVar.
            if ($info['model'] === null && $info['with'] === []) {
                unset($this->variables[$targetVar]);
            } else {
                $this->variables[$targetVar] = new VariableState($info['model'], $info['with']);
            }
        } elseif ($expr instanceof Node\Expr\MethodCall) {
            $this->processLoadCall($expr);
        }
    }

    /**
     * Merge multiple control-flow branch scopes (e.g. if / elseif / else) back into this scope.
     *
     * @param  non-empty-list<ScopeContext>  $branches
     */
    public function mergeBranches(array $branches): void
    {
        $allVarNames = [];
        foreach ($branches as $branch) {
            foreach (array_keys($branch->variables) as $varName) {
                $allVarNames[$varName] = true;
            }
        }

        $merged = [];
        foreach (array_keys($allVarNames) as $varName) {
            $states = array_map(
                static fn (ScopeContext $branch): ?VariableState => $branch->getVariableState($varName),
                $branches
            );

            // Model is kept only if all branches agree on the same non-null model.
            $models = array_unique(array_map(
                static fn (?VariableState $s): string => $s?->model ?? '',
                $states
            ));
            $agreedModel = (count($models) === 1 && $models[0] !== '') ? $models[0] : null;

            // Eager loads are guaranteed only if present in every branch (intersection).
            $eagerLoadLists = array_map(
                static fn (?VariableState $s): array => $s?->eagerLoads ?? [],
                $states
            );
            $commonEagerLoads = count($eagerLoadLists) === 1
                ? $eagerLoadLists[0]
                : array_values(array_intersect(...$eagerLoadLists));

            if ($agreedModel !== null || $commonEagerLoads !== []) {
                $merged[$varName] = new VariableState($agreedModel, $commonEagerLoads);
            }
        }

        $this->variables = $merged;
    }

    /**
     * Inspect an expression such as `User::with('posts')->get()`, `$query->get()`, or `$users`.
     *
     * @return array{model: ?string, with: list<string>}
     */
    public function inspectCollectionExpression(Node\Expr $expr): array
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return [
                'model' => $this->getVariableModel($expr->name),
                'with' => $this->getEagerLoads($expr->name),
            ];
        }

        // Handle $user->posts (relation collection property access)
        if ($expr instanceof Node\Expr\PropertyFetch
            && $expr->var instanceof Node\Expr\Variable
            && is_string($expr->var->name)
            && $expr->name instanceof Node\Identifier
        ) {
            $parentVar = $expr->var->name;
            $relationName = $expr->name->toString();
            $parentModelName = $this->getVariableModel($parentVar);

            $childModel = null;
            if ($parentModelName !== null) {
                $parentModel = $this->modelRegistry->findByClassName($parentModelName);
                $childModel = $parentModel?->getRelatedModel($relationName);
            }

            $nestedWith = [];
            $prefix = $relationName.'.';
            foreach ($this->getEagerLoads($parentVar) as $loaded) {
                if (str_starts_with($loaded, $prefix)) {
                    $nestedWith[] = substr($loaded, strlen($prefix));
                }
            }

            return ['model' => $childModel, 'with' => $nestedWith];
        }

        $with = [];
        $model = null;
        $current = $expr;

        while ($current instanceof Node\Expr\MethodCall) {
            if ($current->name instanceof Node\Identifier) {
                $methodName = $current->name->toString();
                if (in_array($methodName, ['with', 'load', 'loadMissing'], true)) {
                    $with = [...$with, ...PhpParserHelper::extractRelationNamesFromArgs($current->args)];
                }
            }
            $current = $current->var;
        }

        if ($current instanceof Node\Expr\StaticCall) {
            $className = PhpParserHelper::resolveClassName($current->class);
            if ($className !== null && $this->modelRegistry->isKnownModelClass($className)) {
                $model = $className;
            }

            if ($current->name instanceof Node\Identifier
                && in_array($current->name->toString(), ['with', 'load'], true)
            ) {
                $with = [...$with, ...PhpParserHelper::extractRelationNamesFromArgs($current->args)];
            }
        } elseif ($current instanceof Node\Expr\Variable && is_string($current->name)) {
            $model = $this->getVariableModel($current->name);
            $with = [...$with, ...$this->getEagerLoads($current->name)];
        }

        return [
            'model' => $model,
            'with' => array_values(array_unique($with)),
        ];
    }

    /**
     * Derive a child ScopeContext for a loop iteration variable, e.g. `foreach ($users as $user)`.
     */
    public function enterLoop(Node\Expr $iteratedExpr, string $itemVarName): self
    {
        $child = clone $this;
        $info = $this->inspectCollectionExpression($iteratedExpr);

        $child->setVariableState(
            $itemVarName,
            new VariableState($info['model'], $info['with'])
        );

        return $child;
    }

    private function processLoadCall(Node\Expr\MethodCall $call): void
    {
        $current = $call;
        $loads = [];

        while ($current instanceof Node\Expr\MethodCall) {
            if ($current->name instanceof Node\Identifier
                && in_array($current->name->toString(), ['load', 'loadMissing'], true)
            ) {
                $loads = [...$loads, ...PhpParserHelper::extractRelationNamesFromArgs($current->args)];
            }
            $current = $current->var;
        }

        if ($loads !== [] && $current instanceof Node\Expr\Variable && is_string($current->name)) {
            $this->addEagerLoads($current->name, $loads);
        }
    }
}
