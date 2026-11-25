<?php

namespace NPlusOneScanner\Analysis;

use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Support\PhpParserHelper;
use PhpParser\Node;

final class ScopeContext
{
    /** @var array<string, string> Map of variableName => ModelClass (FQN or short name) */
    private array $variableModels = [];

    /** @var array<string, list<string>> Map of variableName => list of eager-loaded relation paths */
    private array $variableEagerLoads = [];

    public function __construct(
        private readonly ModelRegistry $modelRegistry,
    ) {}

    public function setVariableModel(string $varName, string $modelClass): void
    {
        $this->variableModels[$varName] = $modelClass;
    }

    public function getVariableModel(string $varName): ?string
    {
        return $this->variableModels[$varName] ?? null;
    }

    /**
     * @param  list<string>  $relations
     */
    public function addEagerLoads(string $varName, array $relations): void
    {
        $existing = $this->variableEagerLoads[$varName] ?? [];
        $this->variableEagerLoads[$varName] = array_values(array_unique([...$existing, ...$relations]));
    }

    /**
     * @return list<string>
     */
    public function getEagerLoads(string $varName): array
    {
        return $this->variableEagerLoads[$varName] ?? [];
    }

    public function isRelationEagerLoaded(string $varName, string $relation): bool
    {
        foreach ($this->getEagerLoads($varName) as $loaded) {
            if ($loaded === $relation || str_starts_with($loaded, $relation.'.')) {
                return true;
            }
        }

        $modelName = $this->getVariableModel($varName);
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
        if ($stmt instanceof Node\Stmt\Expression) {
            $expr = $stmt->expr;

            if ($expr instanceof Node\Expr\Assign && $expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
                $targetVar = $expr->var->name;
                $info = $this->inspectCollectionExpression($expr->expr);
                if ($info['model'] !== null) {
                    $this->setVariableModel($targetVar, $info['model']);
                }
                if ($info['with'] !== []) {
                    $this->addEagerLoads($targetVar, $info['with']);
                }
            } elseif ($expr instanceof Node\Expr\MethodCall) {
                $this->processLoadCall($expr);
            }
        }
    }

    /**
     * Inspect an expression such as `User::with('posts')->get()` or `$users`.
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

        if ($info['model'] !== null) {
            $child->setVariableModel($itemVarName, $info['model']);
        }

        if ($info['with'] !== []) {
            $child->variableEagerLoads[$itemVarName] = $info['with'];
        } else {
            unset($child->variableEagerLoads[$itemVarName]);
        }

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
