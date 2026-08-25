<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowLifecycle;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionCancelRequestedEventAttributes;

/**
 * L'annulation Temporal est coopérative : le serveur n'enregistre qu'une demande et replanifie
 * une tâche. Tant que le worker rejoue l'historique sans émettre
 * COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION, l'exécution continue de tourner.
 */
final class TemporalWorkflowCancellationTest extends TestCase
{
    public function testHistoryExposesTheCancelRequest(): void
    {
        $history = TemporalExecutionHistory::fromEvents([$this->cancelRequestedEvent('user requested')]);

        self::assertSame('user requested', $history->cancellationRequestedCause());
    }

    public function testHistoryWithoutCancelRequestExposesNothing(): void
    {
        self::assertNull(TemporalExecutionHistory::fromEvents([])->cancellationRequestedCause());
    }

    public function testCancelRequestEmitsTheCancelCommandAndStopsTheRun(): void
    {
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'exec-1');
        $lifecycle = new TemporalWorkflowLifecycle($buffer, 'parent_request_cancel');

        try {
            $lifecycle->onBeforeRun('exec-1');
            self::fail('onBeforeRun doit empêcher le fiber de démarrer');
        } catch (WorkflowCancelledException $e) {
            self::assertSame('parent_request_cancel', $e->reason);
        }

        $commands = $buffer->peek();
        self::assertCount(1, $commands);
        self::assertSame(CommandType::COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION, $commands[0]->getCommandType());
        self::assertNotNull($commands[0]->getCancelWorkflowExecutionCommandAttributes()?->getDetails());
    }

    public function testWithoutCancelRequestTheRunStartsNormally(): void
    {
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'exec-1');

        (new TemporalWorkflowLifecycle($buffer))->onBeforeRun('exec-1');

        self::assertSame([], $buffer->peek());
    }

    private function cancelRequestedEvent(string $cause): HistoryEvent
    {
        $attrs = new WorkflowExecutionCancelRequestedEventAttributes();
        $attrs->setCause($cause);

        $event = new HistoryEvent();
        $event->setEventId(4);
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCEL_REQUESTED);
        $event->setWorkflowExecutionCancelRequestedEventAttributes($attrs);

        return $event;
    }
}
