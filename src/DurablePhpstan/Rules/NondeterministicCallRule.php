<?php

declare(strict_types=1);

namespace Gplanchat\Durable\PHPStan\Rules;

use Gplanchat\Durable\Attribute\AsWorkflow;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports a clock or randomness read in a workflow class.
 *
 * A workflow method is replayed from its journal: a value it reads from the clock differs on every
 * replay, and the replay then diverges from what it recorded. The whole class is checked, not only
 * `#[AsWorkflowMethod]`: signal and query handlers and private helpers are replayed too.
 *
 * @implements Rule<CallLike>
 */
final class NondeterministicCallRule implements Rule
{
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $scope->getClassReflection();
        if (null === $class || [] === $class->getNativeReflection()->getAttributes(AsWorkflow::class)) {
            return [];
        }
        $label = ClockSources::of($node, $scope);
        if (null === $label) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf('%s reads the current time or waits on the worker, which differs on every replay.', $label))
                ->identifier('durable.nondeterministic')
                ->tip('Wrap it in $env->sideEffect(static fn () => …) so the value is journalled, compute it in an activity, or use $env->sleep() / $env->timer() to wait.')
                ->build(),
        ];
    }
}
