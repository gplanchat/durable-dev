<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * `WorkflowFiberDriver::run()` reads the execution id from its context, and no longer takes it
 * first (#682). Drops that argument from a call that still passes four: the set runs on every
 * upgrade, so a call already down to three is left alone. A call with named arguments is left too.
 */
final class WorkflowFiberDriverRunRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Drop the execution id from WorkflowFiberDriver::run(), which reads it from its context',
            [new CodeSample(
                '$driver->run($executionId, $context, $environment, $handler);',
                '$driver->run($context, $environment, $handler);',
            )],
        );
    }

    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        \assert($node instanceof MethodCall);

        if ($node->isFirstClassCallable() || !$this->isName($node->name, 'run') || 4 !== \count($node->args)
            || !$this->isObjectType($node->var, new ObjectType('Gplanchat\Durable\Worker\WorkflowFiberDriver'))) {
            return null;
        }

        foreach ($node->args as $arg) {
            // A named call is left for the type checker: which argument to drop is its name, not its place.
            if ($arg instanceof Arg && null !== $arg->name) {
                return null;
            }
        }

        array_shift($node->args);

        return $node;
    }
}
