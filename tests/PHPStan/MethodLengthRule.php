<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Enforces the project rule: no method/function body longer than N lines
 * (measured from the first to the last statement). See CLAUDE.md / ADR-0002.
 *
 * @implements Rule<FunctionLike>
 */
final class MethodLengthRule implements Rule
{
    public function __construct(private readonly int $maxLines = 15) {}

    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof ClassMethod && ! $node instanceof Function_) {
            return [];
        }

        $lines = $this->bodyLength($node);

        if ($lines <= $this->maxLines) {
            return [];
        }

        return [$this->error($node, $lines)];
    }

    private function bodyLength(ClassMethod|Function_ $node): int
    {
        $stmts = $node->getStmts() ?? [];

        if ($stmts === []) {
            return 0;
        }

        return end($stmts)->getEndLine() - $stmts[0]->getStartLine() + 1;
    }

    private function error(ClassMethod|Function_ $node, int $lines): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            '%s() body is %d lines; the maximum is %d. Split it by concern.',
            $node->name->toString(),
            $lines,
            $this->maxLines,
        ))->identifier('pixelforge.methodLength')->build();
    }
}
