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
 * one (#682): the constructor of any class implementing `Event`, the named factories below, and
 * the constructors of the classes that open a pass.
 *
 * Every positional argument moves whose reflected parameter accepts an `ExecutionId`: the
 * execution the event belongs to, and the other one it names (a child, a parent, the next run).
 * A custom event its author has not retyped yet is left alone. Like
 * {@see ExecutionIdArgumentRector}, it touches an argument it can prove is a string, and leaves a
 * named, unpacked, nullable or untyped one for the type checker to name.
 */
final class ExecutionIdEventArgumentRector extends AbstractRector
{
    /**
     * Class => its static methods that take an execution id: the events' factories, the two
     * helpers that build a failure event, the one that opens a pass, and the timer and wait readers.
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
        'Gplanchat\Bridge\Temporal\Store\TemporalEventConverter' => ['forHistory'],
        'Gplanchat\Durable\Store\PassEventStore' => ['open'],
        'Gplanchat\Durable\Timer\PendingTimers' => ['of', 'dueAt'],
        'Gplanchat\Durable\Timer\TimerWakeDelayCalculator' => ['millisecondsUntilNextTimerDue'],
        'Gplanchat\Durable\Observation\WaitReason' => ['describe'],
    ];

    /** Classes other than events whose constructor takes the execution id. */
    public const CONSTRUCTORS = [
        'Gplanchat\Durable\ExecutionContext',
        'Gplanchat\Durable\Store\EventStoreCommandBuffer',
        'Gplanchat\Bridge\Temporal\Store\TemporalEventConverter',
        'Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer',
        'Gplanchat\Durable\Store\EventStoreHistorySource',
        'Gplanchat\Durable\Exception\ContinueAsNewRequested',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Wrap a string execution id passed to a Durable journal event in ExecutionId::fromString()',
            [new CodeSample(
                'new TimerCompleted($executionId, $timerId);',
                'new TimerCompleted(ExecutionId::fromString($executionId), $timerId);',
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
            ? is_subclass_of($class, Event::class) || \in_array($class, self::CONSTRUCTORS, true)
            : \in_array($method, self::FACTORIES[$class] ?? [], true);
        if (!$applies || null === $method || !method_exists($class, $method)) {
            return null;
        }

        $changed = false;
        foreach ((new \ReflectionMethod($class, $method))->getParameters() as $position => $parameter) {
            $arg = $node->args[$position] ?? null;
            if (!$arg instanceof Arg || null !== $arg->name || $arg->unpack || !self::takesAnExecutionId($parameter)
                || !$this->getType($arg->value)->isString()->yes()) {
                continue;
            }

            $arg->value = new StaticCall(new FullyQualified(ExecutionId::class), 'fromString', [new Arg($arg->value)]);
            $changed = true;
        }

        return $changed ? $node : null;
    }

    /**
     * A custom event is retyped by hand (UPGRADE): until its parameter accepts an ExecutionId, a
     * wrap would be a TypeError.
     */
    private static function takesAnExecutionId(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $one) {
            if ($one instanceof \ReflectionNamedType && ExecutionId::class === $one->getName()) {
                return true;
            }
        }

        return false;
    }
}
