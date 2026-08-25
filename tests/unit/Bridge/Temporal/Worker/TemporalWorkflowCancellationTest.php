<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowLifecycle;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\NullEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Worker\WorkflowFiberDriver;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\MarkerRecordedEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionCancelRequestedEventAttributes;

/**
 * L'annulation Temporal est coopérative : le serveur n'enregistre qu'une demande et replanifie
 * une tâche. Le worker doit relever un CanceledFailure dans le fiber — pour laisser le workflow
 * compenser — puis répondre par COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION.
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

    public function testCancellationIsRaisedInTheFiberThenAnsweredWithTheCancelCommand(): void
    {
        $seen = null;
        $commands = $this->drive(
            TemporalExecutionHistory::fromEvents([$this->cancelRequestedEvent('operator')]),
            static function (WorkflowEnvironment $env) use (&$seen): mixed {
                try {
                    return $env->await($env->activity('charge', []));
                } catch (WorkflowCancelledFailure $e) {
                    $seen = $e;

                    throw $e;
                }
            },
        );

        self::assertInstanceOf(WorkflowCancelledFailure::class, $seen, 'le workflow doit pouvoir attraper l’annulation');
        self::assertContains(CommandType::COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION, $this->commandTypes($commands));
    }

    public function testCompensationSchedulesItsActivityInsteadOfBeingCancelledInTurn(): void
    {
        $commands = $this->drive(
            TemporalExecutionHistory::fromEvents([$this->cancelRequestedEvent('operator')]),
            static function (WorkflowEnvironment $env): mixed {
                try {
                    return $env->await($env->activity('charge', []));
                } catch (WorkflowCancelledFailure $e) {
                    $env->await($env->activity('refund', []));

                    throw $e;
                }
            },
        );

        // La compensation doit être planifiée : l'annulation ne se livre qu'une fois par tâche.
        $types = $this->commandTypes($commands);
        self::assertContains(CommandType::COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK, $types);
        self::assertNotContains(CommandType::COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION, $types);
    }

    public function testActivityCancellationCarriesTheRealScheduledEventId(): void
    {
        // scheduledEventId doit désigner l'événement ACTIVITY_TASK_SCHEDULED réel : il sortait
        // d'un compteur local partant de 1000, que le serveur aurait rejeté.
        $history = TemporalExecutionHistory::fromEvents([
            $this->activityScheduled(17, 'act-7', 'charge'),
            $this->cancelRequestedEvent('operator'),
        ]);

        $commands = $this->drive($history, static function (WorkflowEnvironment $env): mixed {
            try {
                return $env->await($env->activity('charge', []));
            } catch (WorkflowCancelledFailure $e) {
                throw $e;
            }
        });

        $cancel = null;
        foreach ($commands as $command) {
            if (CommandType::COMMAND_TYPE_REQUEST_CANCEL_ACTIVITY_TASK === $command->getCommandType()) {
                $cancel = $command->getRequestCancelActivityTaskCommandAttributes();
            }
        }

        self::assertNotNull($cancel, 'l’activité en attente doit être annulée côté serveur');
        self::assertSame(17, $cancel->getScheduledEventId());
    }

    public function testDeliveryIsRecordedAndReplaysAsTheSameFailure(): void
    {
        // L'historique Temporal ne porte pas la raison d'une annulation d'opération : sans
        // marqueur, un ACTIVITY_TASK_CANCELED se relirait en ActivitySupersededException et le
        // catch du workflow ne matcherait plus au rejeu.
        $history = TemporalExecutionHistory::fromEvents([
            $this->activityScheduled(17, 'act-7', 'charge'),
            $this->cancelRequestedEvent('operator'),
        ]);
        $commands = $this->drive($history, static function (WorkflowEnvironment $env): mixed {
            try {
                return $env->await($env->activity('charge', []));
            } catch (WorkflowCancelledFailure $e) {
                throw $e;
            }
        });

        $marker = null;
        foreach ($commands as $command) {
            if (CommandType::COMMAND_TYPE_RECORD_MARKER === $command->getCommandType()) {
                $marker = $command->getRecordMarkerCommandAttributes();
            }
        }
        self::assertNotNull($marker);
        self::assertSame(TemporalExecutionHistory::MARKER_CANCELLATION_DELIVERED, $marker->getMarkerName());

        // Tâche suivante : le marqueur est dans l'historique, l'annulation n'est plus relivrée
        // et l'activité se relit avec la MÊME exception.
        $replayed = TemporalExecutionHistory::fromEvents([
            $this->activityScheduled(17, 'act-7', 'charge'),
            $this->cancelRequestedEvent('operator'),
            $this->markerRecorded(21, $marker->getMarkerName(), $marker->getDetails()),
        ]);

        self::assertTrue($replayed->cancellationAlreadyDelivered());
        $slot = $replayed->findActivitySlotResult(0);
        self::assertInstanceOf(WorkflowCancelledFailure::class, $slot['failed'] ?? null);
    }

    public function testSideEffectMarkerRoundTripsThroughTheCommand(): void
    {
        // details est une map<string, Payloads> : un Payload seul y était refusé par protobuf.
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'exec-1');
        $buffer->recordSideEffect('se-1', ['value' => 7]);

        $marker = $buffer->peek()[0]->getRecordMarkerCommandAttributes();
        self::assertNotNull($marker);

        $history = TemporalExecutionHistory::fromEvents([
            $this->markerRecorded(11, $marker->getMarkerName(), $marker->getDetails()),
        ]);
        self::assertSame(['value' => 7], $history->findSideEffectForSlot(0));
    }

    public function testWithoutCancelRequestTheRunProceeds(): void
    {
        $commands = $this->drive(
            TemporalExecutionHistory::fromEvents([]),
            static fn (WorkflowEnvironment $env): mixed => $env->await($env->activity('charge', [])),
        );

        self::assertNotContains(CommandType::COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION, $this->commandTypes($commands));
    }

    // -------------------------------------------------------------------------

    /** @return list<\Temporal\Api\Command\V1\Command> */
    private function drive(TemporalExecutionHistory $history, callable $handler): array
    {
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'exec-1', $history);
        $context = new ExecutionContext('exec-1', $history, $buffer);
        $runtime = new ExecutionRuntime(
            new NullEventStore(),
            new NoopActivityTransport(),
            new RegistryActivityExecutor(),
            0,
            null,
            true,
        );

        (new WorkflowFiberDriver(new TemporalWorkflowLifecycle($buffer, $history->cancellationRequestedCause())))
            ->run('exec-1', $context, new WorkflowEnvironment($context, $runtime), $handler);

        return $buffer->peek();
    }

    /**
     * @param list<\Temporal\Api\Command\V1\Command> $commands
     *
     * @return list<int>
     */
    private function commandTypes(array $commands): array
    {
        return array_map(static fn (\Temporal\Api\Command\V1\Command $c): int => $c->getCommandType(), $commands);
    }

    private function activityScheduled(int $eventId, string $activityId, string $activityType): HistoryEvent
    {
        $attrs = new ActivityTaskScheduledEventAttributes();
        $attrs->setActivityId($activityId);
        $attrs->setActivityType(new ActivityType(['name' => $activityType]));

        $event = new HistoryEvent();
        $event->setEventId($eventId);
        $event->setEventType(EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);
        $event->setActivityTaskScheduledEventAttributes($attrs);

        return $event;
    }

    private function markerRecorded(int $eventId, string $name, mixed $details): HistoryEvent
    {
        $attrs = new MarkerRecordedEventAttributes();
        $attrs->setMarkerName($name);
        $attrs->setDetails($details);

        $event = new HistoryEvent();
        $event->setEventId($eventId);
        $event->setEventType(EventType::EVENT_TYPE_MARKER_RECORDED);
        $event->setMarkerRecordedEventAttributes($attrs);

        return $event;
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
