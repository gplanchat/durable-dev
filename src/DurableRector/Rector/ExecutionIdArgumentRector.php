<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use Gplanchat\Durable\ExecutionId;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name\FullyQualified;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Wraps a string execution id in `ExecutionId::fromString()` where a port now takes the value
 * object (#638, #682).
 *
 * It only touches an argument it can prove is a string, on a receiver it can prove is one of the
 * ports. A named argument, an unpacked one, or one whose type is unknown is left as it is: the type
 * checker names what is left. It does not migrate the code that consumes a return value that became
 * an `ExecutionId`, nor any call made inside a class that implements a port — UPGRADE.md describes
 * both by hand.
 */
final class ExecutionIdArgumentRector extends AbstractRector
{
    /**
     * Port => method => positions of the execution id arguments.
     */
    public const PORTS = [
        'Gplanchat\Durable\Store\EventStoreInterface' => [
            'readStream' => [0], 'readStreamWithRecordedAt' => [0], 'countEventsInStream' => [0],
        ],
        'Gplanchat\Durable\Store\WorkflowMetadataStore' => [
            'save' => [0], 'markCompleted' => [0], 'get' => [0], 'hasActiveWorkflowMetadata' => [0], 'delete' => [0],
        ],
        'Gplanchat\Durable\Port\WorkflowRunCatalogInterface' => ['findRun' => [0]],
        'Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface' => [
            'link' => [0, 1], 'getParentExecutionId' => [0], 'getChildExecutionIdsForParent' => [0], 'unlink' => [0],
        ],
        'Gplanchat\Durable\Port\WorkflowResumeDispatcher' => [
            'dispatchResume' => [0], 'dispatchResumeAwaiting' => [0], 'dispatchNewWorkflowRun' => [0],
        ],
        'Gplanchat\Durable\Port\WorkflowHistorySourceInterface' => [
            'hasChildExecutionId' => [0], 'hasChildExecutionCompletedSuccessfully' => [0],
        ],
        'Gplanchat\Durable\Port\WorkflowBackendInterface' => ['start' => [0]],
        'Gplanchat\Bridge\Temporal\WorkflowClientInterface' => [
            'startAsync' => [2], 'startSync' => [2], 'workflowId' => [0],
        ],
        // #682
        'Gplanchat\Durable\Observation\WorkflowRunProjectionInterface' => ['recordStart' => [0], 'recordOutcome' => [0]],
        'Gplanchat\Durable\Observation\WorkflowRunWaitProjectionInterface' => ['recordWait' => [0]],
        'Gplanchat\Durable\Observation\WorkflowRunPickupProjectionInterface' => ['recordPickup' => [0]],
        'Gplanchat\Durable\Debug\WorkflowDispatchObserverInterface' => ['onWorkflowDispatchRequested' => [0]],
        'Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface' => ['onWorkflowRun' => [0], 'onActivityExecuted' => [0]],
        'Gplanchat\Durable\Transport\ActivityTransportInterface' => ['removePendingFor' => [0]],
        'Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface' => ['onParentClosed' => [0]],
        'Gplanchat\Durable\Port\WorkflowLifecycleInterface' => [
            'onBeforeRun' => [0], 'isCancellationPending' => [0], 'onCancellationDelivered' => [0], 'onCancelled' => [0],
            'onCompleted' => [0], 'onSuspended' => [0], 'onContinuedAsNew' => [0], 'onFailed' => [0],
        ],
        'Gplanchat\Durable\Port\WorkflowTimerDispatcher' => ['dispatchTimerFire' => [0]],
        'Gplanchat\Durable\Port\WorkflowCommandBufferInterface' => [
            'scheduleChildWorkflow' => [0], 'completeChildWorkflow' => [0], 'failChildWorkflow' => [0],
        ],
        'Gplanchat\Durable\Port\ChildWorkflowRunnerInterface' => ['runChild' => [0, 3]],
        'Gplanchat\Durable\Port\ActivityAttemptClaimInterface' => ['claim' => [0]],
        'Gplanchat\Durable\Store\FencedEventStoreInterface' => ['claimPass' => [0]],
        'Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory' => ['waitJournal' => [0]],
        'Gplanchat\Durable\Transport\AwaitedFact' => ['isJournalledIn' => [1]],
        'Gplanchat\Durable\ExecutionEngine' => ['start' => [0], 'resume' => [0]],
        'Gplanchat\Durable\InMemoryWorkflowRunner' => ['run' => [0]],
        'Gplanchat\Durable\Exception\ContinueAsNewRequested' => ['withNextExecutionId' => [0]],
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Wrap a string execution id passed to a Durable port in ExecutionId::fromString()',
            [new CodeSample(
                '$eventStore->readStream($executionId);',
                '$eventStore->readStream(\Gplanchat\Durable\ExecutionId::fromString($executionId));',
            )],
        );
    }

    public function getNodeTypes(): array
    {
        return [MethodCall::class, NullsafeMethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        \assert($node instanceof MethodCall || $node instanceof NullsafeMethodCall);

        if ($node->isFirstClassCallable()) {
            return null;
        }

        $method = $this->getName($node->name);
        if (null === $method || $this->isInsideAnImplementation($node)) {
            return null;
        }

        $changed = false;
        foreach (self::PORTS as $port => $methods) {
            if (!isset($methods[$method]) || !$this->isObjectType($node->var, new ObjectType($port))) {
                continue;
            }

            foreach ($methods[$method] as $position) {
                $arg = $node->args[$position] ?? null;
                if (!$arg instanceof Arg || null !== $arg->name || $arg->unpack || !$this->getType($arg->value)->isString()->yes()) {
                    continue;
                }

                $arg->value = new StaticCall(new FullyQualified(ExecutionId::class), 'fromString', [new Arg($arg->value)]);
                $changed = true;
            }

            break;
        }

        return $changed ? $node : null;
    }

    /**
     * A class that implements a port is migrated by hand (UPGRADE): its parameters become
     * ExecutionId, and a wrap written now would turn into fromString(ExecutionId) then.
     */
    private function isInsideAnImplementation(Node $node): bool
    {
        $class = $node->getAttribute(AttributeKey::SCOPE);
        $class = $class instanceof Scope ? $class->getClassReflection() : null;
        if (null === $class) {
            return false;
        }

        foreach (array_keys(self::PORTS) as $port) {
            if ($class->implementsInterface($port)) {
                return true;
            }
        }

        return false;
    }
}
