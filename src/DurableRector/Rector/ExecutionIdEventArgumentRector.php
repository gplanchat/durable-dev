<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\ExecutionId;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Wraps a string execution id in `ExecutionId::fromString()` where a journal event is built from
 * one (#682): the constructor of any class implementing `Event`, and the named factories below.
 *
 * Only the first argument moves, the execution the event belongs to, and only once the
 * reflected parameter is typed `ExecutionId`: a custom event its author has not retyped yet is
 * left alone. Like
 * {@see ExecutionIdArgumentRector}, it touches an argument it can prove is a string, and leaves a
 * named, unpacked, nullable or untyped one for the type checker to name.
 */
final class ExecutionIdEventArgumentRector extends AbstractRector
{
    /**
     * Class => its static factories whose first argument is the execution id: the events' own,
     * and the two helpers that build a failure event.
     */
    public const FACTORIES = [
        'Gplanchat\Durable\Event\ActivityCatastrophicFailure' => ['fromStoredPayload', 'forThrowable'],
        'Gplanchat\Durable\Event\ActivityFailed' => ['fromEnvelope'],
        'Gplanchat\Durable\Event\ActivityTaskFailed' => ['forThrowable'],
        'Gplanchat\Durable\Event\WorkflowExecutionFailed' => [
            'fromStoredPayload', 'unhandledNexusOperationFailure', 'unhandledActivityFailure',
            'unhandledDeclaredActivityFailure', 'unhandledActivitySuperseded', 'deadlineExceeded',
            'unhandledCatastrophicActivity', 'workflowHandlerFailure', 'terminatedByParent',
        ],
        'Gplanchat\Durable\Failure\WorkflowFailureClassifier' => ['classify'],
        'Gplanchat\Durable\Failure\ActivityFailureEventFactory' => ['fromActivityThrowable'],
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Wrap a string execution id passed to a Durable journal event in ExecutionId::fromString()',
            [new CodeSample(
                'new TimerCompleted($executionId, $timerId);',
                'new TimerCompleted(\Gplanchat\Durable\ExecutionId::fromString($executionId), $timerId);',
            )],
        );
    }

    public function getNodeTypes(): array
    {
        return [New_::class, StaticCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        \assert($node instanceof New_ || $node instanceof StaticCall);

        if (!$node->class instanceof Name || $node->isFirstClassCallable()) {
            return null;
        }

        $class = $this->getName($node->class);
        $method = $node instanceof New_ ? '__construct' : $this->getName($node->name);
        $applies = $node instanceof New_
            ? is_subclass_of($class, Event::class)
            : \in_array($method, self::FACTORIES[$class] ?? [], true);
        if (!$applies || null === $method || !self::firstParameterTakesAnExecutionId($class, $method)) {
            return null;
        }

        $arg = $node->args[0] ?? null;
        if (!$arg instanceof Arg || null !== $arg->name || $arg->unpack || !$this->getType($arg->value)->isString()->yes()) {
            return null;
        }

        $arg->value = new StaticCall(new FullyQualified(ExecutionId::class), 'fromString', [new Arg($arg->value)]);

        return $node;
    }

    /**
     * A custom event is retyped by hand (UPGRADE): until its first parameter takes an
     * ExecutionId, a wrap would be a TypeError.
     */
    private static function firstParameterTakesAnExecutionId(string $class, string $method): bool
    {
        if (!method_exists($class, $method)) {
            return false;
        }
        $first = (new \ReflectionMethod($class, $method))->getParameters()[0] ?? null;
        $type = $first?->getType();

        return $type instanceof \ReflectionNamedType && ExecutionId::class === $type->getName();
    }
}
