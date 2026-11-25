<?php

namespace NPlusOneScanner\Analysis;

use PhpParser\Node;

/**
 * Sound boolean guard analyzer for `$target->relationLoaded('relation')`.
 */
final class RelationGuardAnalyzer
{
    /**
     * Extract [targetKey, relation] pairs guaranteed to be loaded when $expr is TRUTHY.
     *
     * @param  callable(Node\Expr): ?string  $resolveTargetKey
     * @return list<array{0: string, 1: string}>
     */
    public function extractPositiveGuards(Node $expr, callable $resolveTargetKey): array
    {
        if ($expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'relationLoaded'
        ) {
            $targetKey = $resolveTargetKey($expr->var);
            $first = $expr->args[0] ?? null;
            if ($targetKey !== null && $first instanceof Node\Arg && $first->value instanceof Node\Scalar\String_) {
                return [[$targetKey, $first->value->value]];
            }

            return [];
        }

        if ($expr instanceof Node\Expr\BooleanNot) {
            return $this->extractNegativeGuards($expr->expr, $resolveTargetKey);
        }

        if ($expr instanceof Node\Expr\BinaryOp\Identical || $expr instanceof Node\Expr\BinaryOp\Equal) {
            $boolCompare = $this->unwrapBooleanComparison($expr->left, $expr->right);
            if ($boolCompare !== null) {
                [$inner, $expected] = $boolCompare;

                return $expected
                    ? $this->extractPositiveGuards($inner, $resolveTargetKey)
                    : $this->extractNegativeGuards($inner, $resolveTargetKey);
            }
        }

        if ($expr instanceof Node\Expr\BinaryOp\NotIdentical || $expr instanceof Node\Expr\BinaryOp\NotEqual) {
            $boolCompare = $this->unwrapBooleanComparison($expr->left, $expr->right);
            if ($boolCompare !== null) {
                [$inner, $expected] = $boolCompare;

                return $expected
                    ? $this->extractNegativeGuards($inner, $resolveTargetKey)
                    : $this->extractPositiveGuards($inner, $resolveTargetKey);
            }
        }

        if ($expr instanceof Node\Expr\BinaryOp\BooleanAnd || $expr instanceof Node\Expr\BinaryOp\LogicalAnd) {
            return $this->uniqueGuards([
                ...$this->extractPositiveGuards($expr->left, $resolveTargetKey),
                ...$this->extractPositiveGuards($expr->right, $resolveTargetKey),
            ]);
        }

        if ($expr instanceof Node\Expr\BinaryOp\BooleanOr || $expr instanceof Node\Expr\BinaryOp\LogicalOr) {
            return $this->intersectGuards(
                $this->extractPositiveGuards($expr->left, $resolveTargetKey),
                $this->extractPositiveGuards($expr->right, $resolveTargetKey),
            );
        }

        return [];
    }

    /**
     * Extract [targetKey, relation] pairs guaranteed to be loaded when $expr is FALSY
     * (e.g., in an `else` branch or after an early-return `if ($expr) { return; }`).
     *
     * @param  callable(Node\Expr): ?string  $resolveTargetKey
     * @return list<array{0: string, 1: string}>
     */
    public function extractNegativeGuards(Node $expr, callable $resolveTargetKey): array
    {
        if ($expr instanceof Node\Expr\BooleanNot) {
            return $this->extractPositiveGuards($expr->expr, $resolveTargetKey);
        }

        if ($expr instanceof Node\Expr\BinaryOp\Identical || $expr instanceof Node\Expr\BinaryOp\Equal) {
            $boolCompare = $this->unwrapBooleanComparison($expr->left, $expr->right);
            if ($boolCompare !== null) {
                [$inner, $expected] = $boolCompare;

                return $expected
                    ? $this->extractNegativeGuards($inner, $resolveTargetKey)
                    : $this->extractPositiveGuards($inner, $resolveTargetKey);
            }
        }

        if ($expr instanceof Node\Expr\BinaryOp\NotIdentical || $expr instanceof Node\Expr\BinaryOp\NotEqual) {
            $boolCompare = $this->unwrapBooleanComparison($expr->left, $expr->right);
            if ($boolCompare !== null) {
                [$inner, $expected] = $boolCompare;

                return $expected
                    ? $this->extractPositiveGuards($inner, $resolveTargetKey)
                    : $this->extractNegativeGuards($inner, $resolveTargetKey);
            }
        }

        if ($expr instanceof Node\Expr\BinaryOp\BooleanOr || $expr instanceof Node\Expr\BinaryOp\LogicalOr) {
            return $this->uniqueGuards([
                ...$this->extractNegativeGuards($expr->left, $resolveTargetKey),
                ...$this->extractNegativeGuards($expr->right, $resolveTargetKey),
            ]);
        }

        if ($expr instanceof Node\Expr\BinaryOp\BooleanAnd || $expr instanceof Node\Expr\BinaryOp\LogicalAnd) {
            return $this->intersectGuards(
                $this->extractNegativeGuards($expr->left, $resolveTargetKey),
                $this->extractNegativeGuards($expr->right, $resolveTargetKey),
            );
        }

        return [];
    }

    /**
     * @return array{0: Node\Expr, 1: bool}|null
     */
    private function unwrapBooleanComparison(Node\Expr $left, Node\Expr $right): ?array
    {
        $rightBool = $this->extractBoolLiteral($right);
        if ($rightBool !== null) {
            return [$left, $rightBool];
        }

        $leftBool = $this->extractBoolLiteral($left);
        if ($leftBool !== null) {
            return [$right, $leftBool];
        }

        return null;
    }

    private function extractBoolLiteral(Node\Expr $expr): ?bool
    {
        if (! $expr instanceof Node\Expr\ConstFetch) {
            return null;
        }

        $name = strtolower($expr->name->toString());

        return match ($name) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    /**
     * @param  list<array{0: string, 1: string}>  $guards
     * @return list<array{0: string, 1: string}>
     */
    private function uniqueGuards(array $guards): array
    {
        $seen = [];
        $unique = [];
        foreach ($guards as [$target, $rel]) {
            $key = $target.':'.$rel;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = [$target, $rel];
            }
        }

        return $unique;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $left
     * @param  list<array{0: string, 1: string}>  $right
     * @return list<array{0: string, 1: string}>
     */
    private function intersectGuards(array $left, array $right): array
    {
        $rightMap = [];
        foreach ($right as [$target, $rel]) {
            $rightMap[$target.':'.$rel] = true;
        }

        $result = [];
        foreach ($left as [$target, $rel]) {
            if (isset($rightMap[$target.':'.$rel])) {
                $result[] = [$target, $rel];
            }
        }

        return $this->uniqueGuards($result);
    }
}
