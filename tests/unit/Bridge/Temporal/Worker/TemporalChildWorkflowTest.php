<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalChildWorkflowRunner;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\ExecutionContext;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\ChildWorkflowExecutionCompletedEventAttributes;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\StartChildWorkflowExecutionInitiatedEventAttributes;

/**
 * Les workflows enfants n'étaient pas utilisables sur le driver Temporal : l'ExecutionContext y
 * était construit sans runner d'enfant, donc executeChildWorkflow() levait une LogicException et
 * la commande START_CHILD_WORKFLOW_EXECUTION n'était atteinte par aucun appelant.
 */
final class TemporalChildWorkflowTest extends TestCase
{
    public function testFirstPassEmitsTheStartCommandAndLeavesTheAwaitablePending(): void
    {
        $buffer = $this->buffer();
        $context = $this->context(TemporalExecutionHistory::fromEvents([]), $buffer);

        $awaitable = $context->executeChildWorkflow('ChildType', ['a' => 1]);

        self::assertFalse($awaitable->isSettled(), 'le parent doit attendre l’issue portée par l’historique');
        $commands = $buffer->peek();
        self::assertCount(1, $commands);
        self::assertSame(CommandType::COMMAND_TYPE_START_CHILD_WORKFLOW_EXECUTION, $commands[0]->getCommandType());
    }

    public function testReplayResolvesTheChildFromHistoryWithoutReschedulingIt(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->initiated('child-1'),
            $this->completed('child-1', 'child-result'),
        ]);
        $buffer = $this->buffer();
        $context = $this->context($history, $buffer);

        $awaitable = $context->executeChildWorkflow('ChildType', ['a' => 1]);

        self::assertTrue($awaitable->isSettled());
        self::assertSame('child-result', $awaitable->getResult());
        self::assertSame([], $buffer->peek(), 'aucune commande ne doit être réémise au replay');
    }

    // -------------------------------------------------------------------------

    private function buffer(): TemporalWorkflowCommandBuffer
    {
        return new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'parent-1');
    }

    private function context(TemporalExecutionHistory $history, TemporalWorkflowCommandBuffer $buffer): ExecutionContext
    {
        return new ExecutionContext('parent-1', $history, $buffer, new TemporalChildWorkflowRunner());
    }

    private function initiated(string $childWorkflowId): HistoryEvent
    {
        $attrs = new StartChildWorkflowExecutionInitiatedEventAttributes();
        $attrs->setWorkflowId($childWorkflowId);

        $event = new HistoryEvent();
        $event->setEventId(5);
        $event->setEventType(EventType::EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_INITIATED);
        $event->setStartChildWorkflowExecutionInitiatedEventAttributes($attrs);

        return $event;
    }

    private function completed(string $childWorkflowId, mixed $result): HistoryEvent
    {
        $payloads = new Payloads();
        $payloads->setPayloads([JsonPlainPayload::encode($result)]);

        $attrs = new ChildWorkflowExecutionCompletedEventAttributes();
        $attrs->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $childWorkflowId]));
        $attrs->setResult($payloads);

        $event = new HistoryEvent();
        $event->setEventId(9);
        $event->setEventType(EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_COMPLETED);
        $event->setChildWorkflowExecutionCompletedEventAttributes($attrs);

        return $event;
    }
}
