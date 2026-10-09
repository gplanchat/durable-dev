<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalChildWorkflowRunner;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\Exception\DurableChildWorkflowFailedException;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\ChildWorkflowExecutionCanceledEventAttributes;
use Temporal\Api\History\V1\ChildWorkflowExecutionCompletedEventAttributes;
use Temporal\Api\History\V1\ChildWorkflowExecutionFailedEventAttributes;
use Temporal\Api\History\V1\ChildWorkflowExecutionTerminatedEventAttributes;
use Temporal\Api\History\V1\ChildWorkflowExecutionTimedOutEventAttributes;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\StartChildWorkflowExecutionFailedEventAttributes;
use Temporal\Api\History\V1\StartChildWorkflowExecutionInitiatedEventAttributes;

/**
 * A child that never produces a result must still release its parent, with the failure the journal
 * backends raise (#980). The history reader settled a child on COMPLETED and FAILED only: a start
 * the server refused, or a child that timed out, was cancelled or was terminated, left the parent
 * waiting for good.
 */
final class AChildThatEndsUnhappilySettlesItsParentTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theUnhappyEndings(): iterable
    {
        yield 'the child failed' => ['failed', 'failed'];
        yield 'the start was refused' => ['start_failed', 'could not be started'];
        yield 'the child timed out' => ['timed_out', 'timed out'];
        yield 'the child was cancelled' => ['canceled', 'was cancelled'];
        yield 'the child was terminated' => ['terminated', 'was terminated'];
    }

    #[DataProvider('theUnhappyEndings')]
    public function testTheHistoryReportsTheChildAsFailedWithTheJournalsException(string $ending, string $wording): void
    {
        $history = TemporalExecutionHistory::fromEvents([$this->initiated('child-1'), $this->ending($ending, 'child-1')]);

        $outcome = $history->findChildWorkflowForSlot(0);

        self::assertNotNull($outcome, 'the parent would wait for ever');
        self::assertInstanceOf(DurableChildWorkflowFailedException::class, $outcome->failed);
        self::assertSame('child-1', $outcome->failed->childExecutionId);
        self::assertSame(\sprintf('Child workflow child-1 %s.', $wording), $outcome->failed->getMessage());
    }

    public function testARefusedStartBelongsToItsSlotWhenTheIdIsReused(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->initiated('child-1', 5),
            $this->initiated('child-1', 6),
            $this->ending('start_failed', 'child-1', 6),
        ]);

        self::assertNull($history->findChildWorkflowForSlot(0), 'slot 0 is still running');
        self::assertInstanceOf(DurableChildWorkflowFailedException::class, $history->findChildWorkflowForSlot(1)?->failed);
    }

    public function testARefusedStartDoesNotOverwriteTheResultOfTheSlotThatUsedTheIdFirst(): void
    {
        $completed = new HistoryEvent();
        $completed->setEventId(8);
        $completed->setEventType(EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_COMPLETED);
        $completed->setChildWorkflowExecutionCompletedEventAttributes(
            (new ChildWorkflowExecutionCompletedEventAttributes())->setWorkflowExecution(new WorkflowExecution(['workflow_id' => 'child-1'])),
        );
        $history = TemporalExecutionHistory::fromEvents([
            $this->initiated('child-1', 5),
            $completed,
            $this->initiated('child-1', 9),
            $this->ending('start_failed', 'child-1', 9),
        ]);

        self::assertNull($history->findChildWorkflowForSlot(0)?->failed);
        self::assertInstanceOf(DurableChildWorkflowFailedException::class, $history->findChildWorkflowForSlot(1)?->failed);
    }

    #[DataProvider('theUnhappyEndings')]
    public function testTheParentsAwaitIsSettledOnReplay(string $ending, string $wording): void
    {
        $history = TemporalExecutionHistory::fromEvents([$this->initiated('child-1'), $this->ending($ending, 'child-1')]);
        $context = new ExecutionContext(
            ExecutionId::fromString('parent-1'),
            $history,
            new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), ExecutionId::fromString('parent-1')),
            new TemporalChildWorkflowRunner(),
        );

        $awaitable = $context->executeChildWorkflow('ChildType', []);

        self::assertTrue($awaitable->isSettled());
    }

    // -------------------------------------------------------------------------

    private function initiated(string $childWorkflowId, int $eventId = 5): HistoryEvent
    {
        $attrs = new StartChildWorkflowExecutionInitiatedEventAttributes();
        $attrs->setWorkflowId($childWorkflowId);

        $event = new HistoryEvent();
        $event->setEventId($eventId);
        $event->setEventType(EventType::EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_INITIATED);
        $event->setStartChildWorkflowExecutionInitiatedEventAttributes($attrs);

        return $event;
    }

    private function ending(string $ending, string $childWorkflowId, int $initiatedEventId = 5): HistoryEvent
    {
        $execution = new WorkflowExecution(['workflow_id' => $childWorkflowId]);
        $event = new HistoryEvent();
        $event->setEventId(9);

        switch ($ending) {
            case 'failed':
                $event->setEventType(EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_FAILED);
                $event->setChildWorkflowExecutionFailedEventAttributes((new ChildWorkflowExecutionFailedEventAttributes())->setWorkflowExecution($execution));
                break;
            case 'start_failed':
                $event->setEventType(EventType::EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_FAILED);
                $event->setStartChildWorkflowExecutionFailedEventAttributes((new StartChildWorkflowExecutionFailedEventAttributes())->setWorkflowId($childWorkflowId)->setInitiatedEventId($initiatedEventId));
                break;
            case 'timed_out':
                $event->setEventType(EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_TIMED_OUT);
                $event->setChildWorkflowExecutionTimedOutEventAttributes((new ChildWorkflowExecutionTimedOutEventAttributes())->setWorkflowExecution($execution));
                break;
            case 'canceled':
                $event->setEventType(EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_CANCELED);
                $event->setChildWorkflowExecutionCanceledEventAttributes((new ChildWorkflowExecutionCanceledEventAttributes())->setWorkflowExecution($execution));
                break;
            case 'terminated':
                $event->setEventType(EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_TERMINATED);
                $event->setChildWorkflowExecutionTerminatedEventAttributes((new ChildWorkflowExecutionTerminatedEventAttributes())->setWorkflowExecution($execution));
                break;
        }

        return $event;
    }
}
