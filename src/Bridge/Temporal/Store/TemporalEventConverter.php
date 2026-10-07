<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Store;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\NexusOperationCancelled;
use Gplanchat\Durable\Event\NexusOperationCompleted;
use Gplanchat\Durable\Event\NexusOperationFailed;
use Gplanchat\Durable\Event\NexusOperationScheduled;
use Gplanchat\Durable\Event\NexusOperationTimedOut;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\VersionMarked;
use Gplanchat\Durable\Event\WorkflowCancellationDelivered;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\Versioning\ChangePoint;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\RetryState;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\MarkerRecordedEventAttributes;

/**
 * Stateful converter: accumulates cross-event mappings (scheduled-event-id → activity-id,
 * started-event-id → timer-id) while streaming a single execution's Temporal history.
 *
 * One instance per execution stream. Do not reuse across executions.
 *
 * Build it with {@see forHistory()} when the cancellation reasons matter: a converter built with
 * `new` only knows the markers it has already seen, and reads a workflow-cancelled operation that
 * was CANCELED before its marker as `race_superseded`.
 */
final class TemporalEventConverter
{
    /** @var array<int, string> scheduledEventId → activityId */
    private array $scheduledEventIdToActivityId = [];

    /** @var array<int, string> startedEventId → timerId */
    private array $startedEventIdToTimerId = [];

    private int $sideEffectSlot = 0;

    /** @var array<int, array{updateId: string, name: string, arguments: array<string, mixed>}> acceptedEventId → accepted update awaiting its completion */
    private array $acceptedUpdates = [];

    /** @var array<string, true> operations the delivered-cancellation marker targets */
    private array $cancellationDeliveredTargets = [];

    private readonly ExecutionId $id;

    public function __construct(ExecutionId $executionId)
    {
        $this->id = $executionId;
    }

    /**
     * A converter that knows every delivered-cancellation marker of the history before it converts
     * the first event. A server runs a workflow task's commands in turn, cancellations before the
     * marker, so an operation that was not running is CANCELED before the marker that explains it.
     *
     * A list, not an iterable: the caller converts the same history afterwards, and a generator
     * would be used up by then.
     *
     * @param list<HistoryEvent> $history the whole history of the execution
     */
    public static function forHistory(ExecutionId $executionId, array $history): self
    {
        $converter = new self($executionId);
        foreach ($history as $event) {
            $attr = $event->getMarkerRecordedEventAttributes();
            if (null !== $attr && TemporalExecutionHistory::MARKER_CANCELLATION_DELIVERED === $attr->getMarkerName()) {
                $converter->cancellationDelivered(self::deliveredTargets($attr));
            }
        }

        return $converter;
    }

    /**
     * Convert one Temporal HistoryEvent to a Durable Event.
     * Returns null for event types that have no Durable equivalent
     * (e.g. ACTIVITY_TASK_STARTED which is Temporal-internal scaffolding).
     */
    public function convert(HistoryEvent $event): ?Event
    {
        $type = $event->getEventType();
        $eventId = (int) $event->getEventId();
        $ts = $this->eventTimestamp($event);

        switch ($type) {
            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED:
                $attr = $event->getWorkflowExecutionStartedEventAttributes();
                $payload = [];
                if (null !== $attr) {
                    $inputPayloads = $attr->getInput();
                    if (null !== $inputPayloads) {
                        $ps = $inputPayloads->getPayloads();
                        if ($ps->count() > 0) {
                            $decoded = JsonPlainPayload::decode($ps[0]);
                            $payload = \is_array($decoded) ? $decoded : ['args' => $decoded];
                        }
                    }
                }

                return new ExecutionStarted($this->id, $payload);

            case EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED:
                $attr = $event->getNexusOperationScheduledEventAttributes();
                if (null === $attr) {
                    return null;
                }

                // The identity of a Nexus operation is the eventId of its scheduling: it is what
                // Temporal attaches the terminal states to, and therefore the only key that makes
                // it possible to recompose a lifeline in the profiler.
                return new NexusOperationScheduled(
                    $this->id,
                    $eventId,
                    (string) $attr->getEndpoint(),
                    (string) $attr->getService(),
                    (string) $attr->getOperation(),
                );

            case EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED:
                $attr = $event->getNexusOperationCompletedEventAttributes();

                return null === $attr ? null : new NexusOperationCompleted($this->id, $attr->getScheduledEventId());

            case EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED:
                $attr = $event->getNexusOperationFailedEventAttributes();

                return null === $attr ? null : new NexusOperationFailed($this->id, $attr->getScheduledEventId());

            case EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT:
                $attr = $event->getNexusOperationTimedOutEventAttributes();

                return null === $attr ? null : new NexusOperationTimedOut($this->id, $attr->getScheduledEventId());

            case EventType::EVENT_TYPE_NEXUS_OPERATION_CANCELED:
                $attr = $event->getNexusOperationCanceledEventAttributes();

                return null === $attr ? null : new NexusOperationCancelled($this->id, $attr->getScheduledEventId());

            case EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED:
                $attr = $event->getActivityTaskScheduledEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $activityId = (string) $attr->getActivityId();
                $this->scheduledEventIdToActivityId[$eventId] = $activityId;

                $activityType = '';
                $at = $attr->getActivityType();
                if (null !== $at) {
                    $activityType = (string) $at->getName();
                }

                $input = [];
                $inputPayloads = $attr->getInput();
                if (null !== $inputPayloads) {
                    $ps = $inputPayloads->getPayloads();
                    if ($ps->count() > 0) {
                        $decoded = JsonPlainPayload::decode($ps[0]);
                        $input = \is_array($decoded) ? $decoded : [];
                    }
                }

                // Durable's worker writes an envelope (TemporalActivityScheduleInput): the
                // arguments are its `payload`, the scheduling metadata its `metadata`.
                if (isset($input['activityId']) && \is_array($input['payload'] ?? null)) {
                    return new ActivityScheduled($this->id, $activityId, $activityType, $input['payload'], \is_array($input['metadata'] ?? null) ? $input['metadata'] : []);
                }

                return new ActivityScheduled($this->id, $activityId, $activityType, $input);

            case EventType::EVENT_TYPE_ACTIVITY_TASK_COMPLETED:
                $attr = $event->getActivityTaskCompletedEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $activityId = $this->scheduledEventIdToActivityId[$attr->getScheduledEventId()] ?? null;
                if (null === $activityId) {
                    return null;
                }

                $result = null;
                $resultPayloads = $attr->getResult();
                if (null !== $resultPayloads) {
                    $ps = $resultPayloads->getPayloads();
                    if ($ps->count() > 0) {
                        $result = JsonPlainPayload::decode($ps[0]);
                    }
                }

                return new ActivityCompleted($this->id, $activityId, $result);

            case EventType::EVENT_TYPE_ACTIVITY_TASK_FAILED:
                $attr = $event->getActivityTaskFailedEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $activityId = $this->scheduledEventIdToActivityId[$attr->getScheduledEventId()] ?? null;
                if (null === $activityId) {
                    return null;
                }

                $failure = $attr->getFailure();
                $msg = null !== $failure ? $failure->getMessage() : 'Activity failed';
                $type = $failure?->getApplicationFailureInfo()?->getType();

                return new ActivityFailed(
                    $this->id,
                    $activityId,
                    \is_string($type) && '' !== $type ? $type : \RuntimeException::class,
                    $msg,
                    retryState: self::toActivityRetryState($attr->getRetryState()),
                );

            case EventType::EVENT_TYPE_ACTIVITY_TASK_CANCELED:
                $attr = $event->getActivityTaskCanceledEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $activityId = $this->scheduledEventIdToActivityId[$attr->getScheduledEventId()] ?? null;
                if (null === $activityId) {
                    return null;
                }

                return new ActivityCancelled($this->id, $activityId, $this->cancellationReason($activityId));

            case EventType::EVENT_TYPE_TIMER_STARTED:
                $attr = $event->getTimerStartedEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $timerId = (string) $attr->getTimerId();
                $this->startedEventIdToTimerId[$eventId] = $timerId;
                // `scheduledAt()` is the deadline on every store, not when the timer was started (#586).
                $timeout = $attr->getStartToFireTimeout();
                $deadline = $ts + (null === $timeout ? 0.0 : (float) $timeout->getSeconds() + (float) $timeout->getNanos() / 1_000_000_000.0);

                return new TimerScheduled($this->id, $timerId, $deadline);

            case EventType::EVENT_TYPE_TIMER_FIRED:
                $attr = $event->getTimerFiredEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $timerId = $this->startedEventIdToTimerId[$attr->getStartedEventId()] ?? null;
                if (null === $timerId) {
                    return null;
                }

                return new TimerCompleted($this->id, $timerId);

            case EventType::EVENT_TYPE_MARKER_RECORDED:
                $attr = $event->getMarkerRecordedEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $details = $attr->getDetails();
                $detail = static fn(string $key): mixed => null !== $details && $details->offsetExists($key)
                    ? JsonPlainPayload::decodePayloads($details->offsetGet($key))[0] ?? null
                    : null;

                // By name, as TemporalExecutionHistory reads them: only a side effect takes a slot,
                // or the version marker shifts every side effect after it.
                return match ($attr->getMarkerName()) {
                    TemporalExecutionHistory::MARKER_SIDE_EFFECT => new SideEffectRecorded($this->id, (string) $this->sideEffectSlot++, $detail('result')),
                    ChangePoint::MARKER_NAME => new VersionMarked($this->id, (string) $detail(ChangePoint::DETAIL_CHANGE_ID), (int) $detail(ChangePoint::DETAIL_VERSION)),
                    TemporalExecutionHistory::MARKER_CANCELLATION_DELIVERED => $this->cancellationDelivered(self::deliveredTargets($attr)),
                    default => null,
                };

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED:
                $attr = $event->getWorkflowExecutionCompletedEventAttributes();
                $result = null;
                if (null !== $attr) {
                    $resultPayloads = $attr->getResult();
                    if (null !== $resultPayloads) {
                        $ps = $resultPayloads->getPayloads();
                        if ($ps->count() > 0) {
                            $result = JsonPlainPayload::decode($ps[0]);
                        }
                    }
                }

                return new ExecutionCompleted($this->id, $result);

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED:
                $attr = $event->getWorkflowExecutionFailedEventAttributes();
                $failure = null !== $attr ? $attr->getFailure() : null;
                $msg = null !== $failure ? $failure->getMessage() : 'Workflow execution failed';

                // The worker serializes the WorkflowExecutionFailed payload into the details of
                // the ApplicationFailureInfo: this is where the original `kind` is read back.
                $stored = self::decodeApplicationFailureDetails($failure);
                if (null !== $stored) {
                    return WorkflowExecutionFailed::fromStoredPayload($this->id, $stored);
                }

                return WorkflowExecutionFailed::workflowHandlerFailure(
                    $this->id,
                    new \RuntimeException($msg),
                );

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCEL_REQUESTED:
                $attr = $event->getWorkflowExecutionCancelRequestedEventAttributes();
                $reason = null !== $attr ? (string) $attr->getCause() : '';

                return new WorkflowCancellationRequested($this->id, '' !== $reason ? $reason : 'Cancel requested by Temporal');

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCELED:
                return new WorkflowExecutionCancelled($this->id, 'Cancelled by Temporal');

            case EventType::EVENT_TYPE_TIMER_CANCELED:
                $attr = $event->getTimerCanceledEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $timerId = $this->startedEventIdToTimerId[$attr->getStartedEventId()] ?? (string) $attr->getTimerId();
                if ('' === $timerId) {
                    return null;
                }

                return new TimerCancelled($this->id, $timerId, $this->cancellationReason($timerId));

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED:
                $attr = $event->getWorkflowExecutionSignaledEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $signalName = (string) $attr->getSignalName();
                $signalInput = [];
                $inputPayloads = $attr->getInput();
                if (null !== $inputPayloads) {
                    $ps = $inputPayloads->getPayloads();
                    if ($ps->count() > 0) {
                        $decoded = JsonPlainPayload::decode($ps[0]);
                        $signalInput = \is_array($decoded) ? $decoded : ['value' => $decoded];
                    }
                }

                return new WorkflowSignalReceived($this->id, $signalName, $signalInput);

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED:
                $attr = $event->getWorkflowExecutionUpdateAcceptedEventAttributes();
                $request = $attr?->getAcceptedRequest();
                if (null === $request) {
                    return null;
                }
                $input = $request->getInput();
                $arguments = [];
                $args = $input?->getArgs()?->getPayloads();
                if (null !== $args && $args->count() > 0) {
                    $decoded = JsonPlainPayload::decode($args[0]);
                    $arguments = \is_array($decoded) ? $decoded : ['value' => $decoded];
                }
                // Kept until the completion arrives: only that event carries the outcome.
                $this->acceptedUpdates[$eventId] = [
                    'updateId' => (string) $request->getMeta()?->getUpdateId(),
                    'name' => null !== $input ? (string) $input->getName() : '',
                    'arguments' => $arguments,
                ];

                return null;

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_COMPLETED:
                $attr = $event->getWorkflowExecutionUpdateCompletedEventAttributes();
                $outcome = $attr?->getOutcome();
                if (null === $attr || null === $outcome) {
                    return null;
                }
                // Same pairing as TemporalExecutionHistory: by update id, else by accepted event id,
                // never by position (#803).
                $updateId = (string) $attr->getMeta()?->getUpdateId();
                $acceptedId = null;
                foreach ($this->acceptedUpdates as $id => $accepted) {
                    if ('' !== $updateId && $accepted['updateId'] === $updateId) {
                        $acceptedId = $id;
                        break;
                    }
                }
                $acceptedId ??= isset($this->acceptedUpdates[(int) $attr->getAcceptedEventId()]) ? (int) $attr->getAcceptedEventId() : null;
                if (null === $acceptedId) {
                    return null;
                }
                $accepted = $this->acceptedUpdates[$acceptedId];
                unset($this->acceptedUpdates[$acceptedId]);

                $failure = $outcome->getFailure();
                if (null !== $failure) {
                    $type = $failure->getApplicationFailureInfo()?->getType();

                    return new WorkflowUpdateHandled(
                        $this->id,
                        $accepted['name'],
                        $accepted['arguments'],
                        null,
                        new FailureEnvelope(\is_string($type) && '' !== $type ? $type : \RuntimeException::class, $failure->getMessage()),
                    );
                }
                $payloads = $outcome->getSuccess()?->getPayloads();

                return new WorkflowUpdateHandled(
                    $this->id,
                    $accepted['name'],
                    $accepted['arguments'],
                    null !== $payloads && $payloads->count() > 0 ? JsonPlainPayload::decode($payloads[0]) : null,
                );

            case EventType::EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_INITIATED:
                $attr = $event->getStartChildWorkflowExecutionInitiatedEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $childWorkflowId = (string) $attr->getWorkflowId();
                $childType = '';
                $cwt = $attr->getWorkflowType();
                if (null !== $cwt) {
                    $childType = (string) $cwt->getName();
                }
                $childInput = [];
                $inputPayloads = $attr->getInput();
                if (null !== $inputPayloads) {
                    $ps = $inputPayloads->getPayloads();
                    if ($ps->count() > 0) {
                        $decoded = JsonPlainPayload::decode($ps[0]);
                        $childInput = \is_array($decoded) ? $decoded : [];
                    }
                }

                return new ChildWorkflowScheduled(
                    $this->id,
                    ExecutionId::fromString($childWorkflowId),
                    $childType,
                    $childInput,
                    ParentClosePolicy::Terminate,
                    $childWorkflowId,
                );

            case EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_COMPLETED:
                $attr = $event->getChildWorkflowExecutionCompletedEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $exec = $attr->getWorkflowExecution();
                if (null === $exec) {
                    return null;
                }
                $childWorkflowId = (string) $exec->getWorkflowId();
                $childResult = null;
                $resultPayloads = $attr->getResult();
                if (null !== $resultPayloads) {
                    $ps = $resultPayloads->getPayloads();
                    if ($ps->count() > 0) {
                        $childResult = JsonPlainPayload::decode($ps[0]);
                    }
                }

                return new ChildWorkflowCompleted($this->id, ExecutionId::fromString($childWorkflowId), $childResult);

            default:
                return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function deliveredTargets(MarkerRecordedEventAttributes $attr): array
    {
        $details = $attr->getDetails();
        $targets = null !== $details && $details->offsetExists('targets')
            ? JsonPlainPayload::decodePayloads($details->offsetGet('targets'))[0] ?? null
            : null;

        return array_values(array_map(strval(...), (array) $targets));
    }

    /**
     * @param list<string> $targets
     */
    private function cancellationDelivered(array $targets): WorkflowCancellationDelivered
    {
        $this->cancellationDeliveredTargets += array_fill_keys($targets, true);

        return new WorkflowCancellationDelivered($this->id, $targets);
    }

    /**
     * The server records one `*_CANCELED` event whatever the workflow cancelled for. The rule is
     * the one `TemporalExecutionHistory` replays with: an operation the delivered-cancellation
     * marker targets went with the workflow, and the workflow cancels any other only as the loser
     * of a race (#701).
     */
    private function cancellationReason(string $operationId): string
    {
        return isset($this->cancellationDeliveredTargets[$operationId])
            ? ActivityCancellationReason::WORKFLOW_CANCELLED
            : ActivityCancellationReason::RACE_SUPERSEDED;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeApplicationFailureDetails(?\Temporal\Api\Failure\V1\Failure $failure): ?array
    {
        $details = $failure?->getApplicationFailureInfo()?->getDetails();
        if (null === $details) {
            return null;
        }
        $payloads = $details->getPayloads();
        if (0 === $payloads->count()) {
            return null;
        }
        $decoded = JsonPlainPayload::decode($payloads[0]);

        return \is_array($decoded) && isset($decoded['kind']) ? $decoded : null;
    }

    private static function toActivityRetryState(int $retryState): ?ActivityRetryState
    {
        return match ($retryState) {
            RetryState::RETRY_STATE_IN_PROGRESS => ActivityRetryState::InProgress,
            RetryState::RETRY_STATE_NON_RETRYABLE_FAILURE => ActivityRetryState::NonRetryableFailure,
            RetryState::RETRY_STATE_TIMEOUT => ActivityRetryState::Timeout,
            RetryState::RETRY_STATE_MAXIMUM_ATTEMPTS_REACHED => ActivityRetryState::MaximumAttemptsReached,
            RetryState::RETRY_STATE_RETRY_POLICY_NOT_SET => ActivityRetryState::RetryPolicyNotSet,
            default => null,
        };
    }

    private function eventTimestamp(HistoryEvent $event): float
    {
        $ts = $event->getEventTime();
        if (null === $ts) {
            return microtime(true);
        }

        return (float) $ts->getSeconds() + ((float) $ts->getNanos() / 1_000_000_000.0);
    }

    public function timestampFor(HistoryEvent $event): \DateTimeImmutable
    {
        $ts = $event->getEventTime();
        if (null === $ts) {
            return new \DateTimeImmutable();
        }

        return \DateTimeImmutable::createFromFormat(
            'U.u',
            \sprintf('%d.%06d', $ts->getSeconds(), (int) ($ts->getNanos() / 1000)),
        ) ?: new \DateTimeImmutable();
    }
}
