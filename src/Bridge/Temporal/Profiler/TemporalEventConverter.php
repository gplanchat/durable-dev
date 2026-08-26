<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Profiler;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\ParentClosePolicy;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\RetryState;
use Temporal\Api\History\V1\HistoryEvent;

/**
 * Stateful converter: accumulates cross-event mappings (scheduled-event-id → activity-id,
 * started-event-id → timer-id) while streaming a single execution's Temporal history.
 *
 * One instance per execution stream. Do not reuse across executions.
 */
final class TemporalEventConverter
{
    /** @var array<int, string> scheduledEventId → activityId */
    private array $scheduledEventIdToActivityId = [];

    /** @var array<int, string> startedEventId → timerId */
    private array $startedEventIdToTimerId = [];

    private int $sideEffectSlot = 0;

    public function __construct(
        private readonly string $executionId,
    ) {}

    /**
     * Convert one Temporal HistoryEvent to a Durable Event.
     * Returns null for event types that have no Durable equivalent
     * (e.g. ACTIVITY_TASK_STARTED which is Temporal-internal scaffolding).
     */
    public function convert(HistoryEvent $event): ?Event
    {
        $type = $event->getEventType();
        $eventId = $event->getEventId();
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

                return new ExecutionStarted($this->executionId, $payload);

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

                return new ActivityScheduled($this->executionId, $activityId, $activityType, $input);

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

                return new ActivityCompleted($this->executionId, $activityId, $result);

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
                    $this->executionId,
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

                return new ActivityCancelled($this->executionId, $activityId, 'Cancelled by Temporal');

            case EventType::EVENT_TYPE_TIMER_STARTED:
                $attr = $event->getTimerStartedEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $timerId = (string) $attr->getTimerId();
                $this->startedEventIdToTimerId[$eventId] = $timerId;

                return new TimerScheduled($this->executionId, $timerId, $ts);

            case EventType::EVENT_TYPE_TIMER_FIRED:
                $attr = $event->getTimerFiredEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $timerId = $this->startedEventIdToTimerId[$attr->getStartedEventId()] ?? null;
                if (null === $timerId) {
                    return null;
                }

                return new TimerCompleted($this->executionId, $timerId);

            case EventType::EVENT_TYPE_MARKER_RECORDED:
                $attr = $event->getMarkerRecordedEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $slot = $this->sideEffectSlot++;
                $details = $attr->getDetails();
                $result = null;
                if (null !== $details && $details->offsetExists('result')) {
                    $detail = $details->offsetGet('result');
                    $payloads = $detail->getPayloads();
                    $result = $payloads->count() > 0 ? JsonPlainPayload::decode($payloads[0]) : null;
                }

                return new SideEffectRecorded($this->executionId, (string) $slot, $result);

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

                return new ExecutionCompleted($this->executionId, $result);

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED:
                $attr = $event->getWorkflowExecutionFailedEventAttributes();
                $failure = null !== $attr ? $attr->getFailure() : null;
                $msg = null !== $failure ? $failure->getMessage() : 'Workflow execution failed';

                // Le worker sérialise le payload du WorkflowExecutionFailed dans les details
                // de l'ApplicationFailureInfo : on récupère ici le `kind` d'origine.
                $stored = self::decodeApplicationFailureDetails($failure);
                if (null !== $stored) {
                    return WorkflowExecutionFailed::fromStoredPayload($this->executionId, $stored);
                }

                return WorkflowExecutionFailed::workflowHandlerFailure(
                    $this->executionId,
                    new \RuntimeException($msg),
                );

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCEL_REQUESTED:
                $attr = $event->getWorkflowExecutionCancelRequestedEventAttributes();
                $reason = null !== $attr ? (string) $attr->getCause() : '';

                return new WorkflowCancellationRequested($this->executionId, '' !== $reason ? $reason : 'Cancel requested by Temporal');

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCELED:
                return new WorkflowExecutionCancelled($this->executionId, 'Cancelled by Temporal');

            case EventType::EVENT_TYPE_TIMER_CANCELED:
                $attr = $event->getTimerCanceledEventAttributes();
                if (null === $attr) {
                    return null;
                }
                $timerId = $this->startedEventIdToTimerId[$attr->getStartedEventId()] ?? (string) $attr->getTimerId();
                if ('' === $timerId) {
                    return null;
                }

                return new TimerCancelled($this->executionId, $timerId, 'Cancelled by Temporal');

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

                return new WorkflowSignalReceived($this->executionId, $signalName, $signalInput);

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
                    $this->executionId,
                    $childWorkflowId,
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

                return new ChildWorkflowCompleted($this->executionId, $childWorkflowId, $childResult);

            default:
                return null;
        }
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
