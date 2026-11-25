<?php

namespace NPlusOneScanner\Analyzers;

use NPlusOneScanner\Analysis\ScopeContext;
use NPlusOneScanner\Contracts\AnalyzerInterface;
use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Discovery\ControllerViewMap;
use NPlusOneScanner\Discovery\ModelRegistry;
use NPlusOneScanner\Support\PhpParserHelper;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class BladeViewAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly ModelRegistry $modelRegistry,
        private readonly ControllerViewMap $controllerViewMap,
        private readonly PhpParserHelper $parserHelper = new PhpParserHelper,
    ) {}

    public function analyze(string $filePath, string $content): array
    {
        $viewName = $this->resolveViewName($filePath);
        $baseScope = new ScopeContext($this->modelRegistry);

        if ($viewName !== null) {
            foreach ($this->controllerViewMap->getContextForView($viewName) as $varName => $info) {
                if ($info['model'] !== null) {
                    $baseScope->setVariableModel($varName, $info['model']);
                }
                if ($info['with'] !== []) {
                    $baseScope->addEagerLoads($varName, $info['with']);
                }
            }
        }

        $lines = preg_split('/\R/', $content) ?: [];
        /** @var list<ScopeContext> $loopScopes */
        $loopScopes = [];
        $issues = [];
        $inPhpBlock = false;
        $phpBuffer = '';
        $phpStartLine = 1;

        foreach ($lines as $index => $lineText) {
            $lineNumber = $index + 1;

            if (preg_match('/@php\b(?!\s*\()/', $lineText)) {
                $inPhpBlock = true;
                $phpBuffer = '';
                $phpStartLine = $lineNumber;

                continue;
            }

            if ($inPhpBlock) {
                if (preg_match('/@endphp\b/', $lineText)) {
                    $inPhpBlock = false;
                    $activeScope = $loopScopes !== [] ? end($loopScopes) : $baseScope;
                    $this->inspectPhpExpression($phpBuffer, $phpStartLine, $activeScope, $loopScopes !== [], $filePath, $lineText, $issues);
                } else {
                    $phpBuffer .= $lineText."\n";
                }

                continue;
            }

            // Detect @foreach ($items as $item) or @forelse ($items as $item)
            if (preg_match('/@(?:foreach|forelse)\s*\((.+)\)/', $lineText, $match)) {
                $parentScope = $loopScopes !== [] ? end($loopScopes) : $baseScope;
                $childScope = $this->deriveLoopScope($match[1], $parentScope);
                $loopScopes[] = $childScope;

                continue;
            }

            if (preg_match('/@(?:endforeach|endforelse)\b/', $lineText)) {
                array_pop($loopScopes);

                continue;
            }

            if ($loopScopes === []) {
                continue;
            }

            $activeScope = end($loopScopes);

            // Extract {{ ... }}, {!! ... !!}, and directive arguments like @if(...), @include(..., [...])
            $expressions = $this->extractBladeExpressions($lineText);
            foreach ($expressions as $exprCode) {
                $this->inspectPhpExpression($exprCode, $lineNumber, $activeScope, true, $filePath, $lineText, $issues);
            }
        }

        return $issues;
    }

    private function deriveLoopScope(string $foreachHeader, ScopeContext $parentScope): ScopeContext
    {
        $ast = $this->parserHelper->parse("<?php foreach ({$foreachHeader}) {}");
        if ($ast === null || ! ($ast[0] instanceof Node\Stmt\Foreach_)) {
            return clone $parentScope;
        }

        $foreach = $ast[0];
        if ($foreach->valueVar instanceof Node\Expr\Variable && is_string($foreach->valueVar->name)) {
            return $parentScope->enterLoop($foreach->expr, $foreach->valueVar->name);
        }

        return clone $parentScope;
    }

    /**
     * @return list<string>
     */
    private function extractBladeExpressions(string $line): array
    {
        $expressions = [];

        if (preg_match_all('/\{\{\s*(.+?)\s*\}\}|\{!!\s*(.+?)\s*!!\}/', $line, $matches)) {
            foreach ($matches[1] as $i => $m1) {
                $expr = $m1 !== '' ? $m1 : $matches[2][$i];
                if ($expr !== '') {
                    $expressions[] = $expr.';';
                }
            }
        }

        if (preg_match_all('/@(?:if|elseif|unless|isset|empty)\s*\((.+)\)/', $line, $dirMatches)) {
            foreach ($dirMatches[1] as $expr) {
                $expressions[] = $expr.';';
            }
        }

        return $expressions;
    }

    /**
     * @param  list<Issue>  $issues
     */
    private function inspectPhpExpression(
        string $phpCode,
        int $lineNumber,
        ScopeContext $scope,
        bool $inLoop,
        string $filePath,
        string $rawLine,
        array &$issues,
    ): void {
        if (! $inLoop) {
            return;
        }

        $ast = $this->parserHelper->parse("<?php\n".$phpCode);
        if ($ast === null) {
            return;
        }

        $nodeFinder = new NodeFinder;
        /** @var list<Node\Expr\PropertyFetch> $propFetches */
        $propFetches = $nodeFinder->findInstanceOf($ast, Node\Expr\PropertyFetch::class);

        foreach ($propFetches as $fetch) {
            if (! $fetch->var instanceof Node\Expr\Variable || ! is_string($fetch->var->name)) {
                continue;
            }

            if (! $fetch->name instanceof Node\Identifier) {
                continue;
            }

            $varName = $fetch->var->name;
            $relationName = $fetch->name->toString();

            if ($scope->isRelationEagerLoaded($varName, $relationName)) {
                continue;
            }

            $resolved = $this->resolveRelation($varName, $relationName, $scope);
            if ($resolved === null) {
                continue;
            }

            [$modelName, $severity] = $resolved;

            $issues[] = new Issue(
                file: $filePath,
                line: $lineNumber,
                type: Issue::TYPE_BLADE_LAZY_LOADING,
                severity: $severity,
                model: $modelName,
                relation: $relationName,
                message: "Blade loop accesses `\${$varName}->{$relationName}` without confirmed eager loading.",
                suggestion: "Eager load `{$relationName}` in the controller using `->with('{$relationName}')` before passing data to the view.",
                snippet: trim($rawLine),
            );
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function resolveRelation(string $varName, string $relationName, ScopeContext $scope): ?array
    {
        $modelClass = $scope->getVariableModel($varName);
        if ($modelClass !== null) {
            $model = $this->modelRegistry->findByClassName($modelClass);
            if ($model !== null && $model->hasRelation($relationName)) {
                return [$model->shortName, Issue::SEVERITY_HIGH];
            }

            return null;
        }

        $candidates = $this->modelRegistry->findModelsWithRelation($relationName);
        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (strcasecmp($varName, $candidate->shortName) === 0) {
                return $candidate->isDefaultEagerLoaded($relationName)
                    ? null
                    : [$candidate->shortName, Issue::SEVERITY_MEDIUM];
            }
        }

        if (count($candidates) === 1 && ! $candidates[0]->isDefaultEagerLoaded($relationName)) {
            return [$candidates[0]->shortName, Issue::SEVERITY_MEDIUM];
        }

        return null;
    }

    private function resolveViewName(string $filePath): ?string
    {
        $normalized = str_replace('\\', '/', $filePath);
        if (preg_match('#resources/views/(.+?)\.blade\.php$#', $normalized, $matches)) {
            return str_replace('/', '.', $matches[1]);
        }

        return null;
    }
}
