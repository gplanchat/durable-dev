<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;

/**
 * Implements WorkflowHistorySourceInterface by reading from Temporal history events.
 *
 * Built from a TemporalHistoryCursor iterator. Indexed on construction for O(1) slot lookups.
 * Used by WorkflowTaskRunner for the Temporal backend.
 */
final class TemporalExecutionHistory implements WorkflowHistorySourceInterface
{
    /** @var list<string> activity IDs in schedule order */
    private array $scheduledActivityIds = [];

    /** @var array<string, int> activity ID → scheduled event ID */
    private array $activityIdToScheduledEventId = [];

    /** @var array<int, string> scheduled event ID → activity ID */
    private array $scheduledEventIdToActivityId = [];

    /** @var array<string, mixed> activity ID → result (for completed activities) */
    private array $activityResults = [];

    /** @var array<string, \Throwable> activity ID → failure */
    private array $activityFailures = [];

    /** @var array<string, string> activity ID → cancellation reason */
    private array $activityCancellations = [];

    /** @var list<string> timer IDs in schedule order */
    private array $scheduledTimerIds = [];

    /** @var array<int, string> start timer event ID → timer ID */
    private array $startedEventIdToTimerId = [];

    /** @var array<string, float> timer ID → scheduled-at */
    private array $timerScheduledAt = [];

    /** @var array<string, true> timer IDs that have fired */
    private array $firedTimerIds = [];

    /** @var array<int, mixed> slot index → side effect result (MARKER_RECORDED events) */
    private array $sideEffects = [];

    /** @var list<array{signalName: string, payload: mixed}> signals in receive order */
    private array $signals = [];

    /** @var list<array{updateName: string, result: mixed}> updates in accept order */
    private array $updates = [];

    /** @var list<string> child execution IDs in schedule order */
    private array $childExecutionIds = [];

    /** @var array<string, array{result: mixed, failed: bool}> child execution ID → outcome */
    private array $childOutcomes = [];

    private int $sideEffectSlot = 0;

    private ?string $durableExecutionId = null;

    /** @var array<string, mixed> */
    private array $startInput = [];

    /**
     * @param iterable<HistoryEvent> $events
     */
    public static function fromEvents(iterable $events): self
    {
        $history = new self();
        foreach ($events as $event) {
            $history->consumeEvent($event);
        }

        return $history;
    }

    private function consumeEvent(HistoryEvent $event): void
    {
        $type = $event->getEventType();
        $eventId = $event->getEventId();

        switch ($type) {
            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED:
                $attr = $event->getWorkflowExecutionStartedEventAttributes();
                if (null !== $attr) {
                    $memo = $attr->getMemo();
                    if (null !== $memo) {
                        $fields = $memo->getFields();
                        if ($fields->offsetExists(JournalExecutionIdResolver::MEMO_KEY_DURABLE_EXECUTION_ID)) {
                            $p = $fields->offsetGet(JournalExecutionIdResolver::MEMO_KEY_DURABLE_EXECUTION_ID);
                            $decoded = JsonPlainPayload::decode($p);
                            if (\is_string($decoded) && '' !== $decoded) {
                                $this->durableExecutionId = $decoded;
                            }
                        }
                    }
                    $inputPayloads = $attr->getInput();
                    if (null !== $inputPayloads) {
                        $payloads = $inputPayloads->getPayloads();
                        if ($payloads->count() > 0) {
                            $decoded = JsonPlainPayload::decode($payloads[0]);
                            $this->startInput = \is_array($decoded) ? $decoded : [];
                        }
                    }
                }
                break;

            case EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED:
                $attr = $event->getActivityTaskScheduledEventAttributes();
                if (null !== $attr) {
                    $activityId = (string) $attr->getActivityId();
                    $this->scheduledActivityIds[] = $activityId;
                    $this->activityIdToScheduledEventId[$activityId] = $eventId;
                    $this->scheduledEventIdToActivityId[$eventId] = $activityId;
                }
                break;

            case EventType::EVENT_TYPE_ACTIVITY_TASK_COMPLETED:
                $attr = $event->getActivityTaskCompletedEventAttributes();
                if (null !== $attr) {
                    $scheduledEventId = $attr->getScheduledEventId();
                    $activityId = $this->scheduledEventIdToActivityId[$scheduledEventId] ?? null;
                    if (null !== $activityId) {
                        $result = null;
                        $resultPayloads = $attr->getResult();
                        if (null !== $resultPayloads) {
                            $payloads = $resultPayloads->getPayloads();
                            if ($payloads->count() > 0) {
                                $result = JsonPlainPayload::decode($payloads[0]);
                            }
                        }
                        $this->activityResults[$activityId] = $result;
                    }
                }
                break;

            case EventType::EVENT_TYPE_ACTIVITY_TASK_FAILED:
                $attr = $event->getActivityTaskFailedEventAttributes();
                if (null !== $attr) {
                    $scheduledEventId = $attr->getScheduledEventId();
                    $activityId = $this->scheduledEventIdToActivityId[$scheduledEventId] ?? null;
                    if (null !== $activityId) {
                        $failure = $attr->getFailure();
                        $message = null !== $failure ? $failure->getMessage() : 'Activity task failed';
                        $this->activityFailures[$activityId] = new \RuntimeException($message);
                    }
                }
                break;

            case EventType::EVENT_TYPE_ACTIVITY_TASK_CANCELED:
                $attr = $event->getActivityTaskCanceledEventAttributes();
                if (null !== $attr) {
                    $scheduledEventId = $attr->getScheduledEventId();
                    $activityId = $this->scheduledEventIdToActivityId[$scheduledEventId] ?? null;
                    if (null !== $activityId) {
                        $this->activityCancellations[$activityId] = 'Cancelled by Temporal';
                    }
                }
                break;

            case EventType::EVENT_TYPE_TIMER_STARTED:
                $attr = $event->getTimerStartedEventAttributes();
                if (null !== $attr) {
                    $timerId = (string) $attr->getTimerId();
                    $this->scheduledTimerIds[] = $timerId;
                    $this->startedEventIdToTimerId[$eventId] = $timerId;
                    $this->timerScheduledAt[$timerId] = 0.0;
                }
                break;

            case EventType::EVENT_TYPE_TIMER_FIRED:
                $attr = $event->getTimerFiredEventAttributes();
                if (null !== $attr) {
                    $startedEventId = $attr->getStartedEventId();
                    $timerId = $this->startedEventIdToTimerId[$startedEventId] ?? null;
                    if (null !== $timerId) {
                        $this->firedTimerIds[$timerId] = true;
                    }
                }
                break;

            case EventType::EVENT_TYPE_MARKER_RECORDED:
                $attr = $event->getMarkerRecordedEventAttributes();
                if (null !== $attr) {
                    $details = $attr->getDetails();
                    $resultPayload = null;
                    if (null !== $details && $details->offsetExists('result')) {
                        $resultPayload = $details->offsetGet('result');
                    }
                    $result = null !== $resultPayload ? JsonPlainPayload::decode($resultPayload) : null;
                    $this->sideEffects[$this->sideEffectSlot++] = $result;
                }
                break;

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED:
                $attr = $event->getWorkflowExecutionSignaledEventAttributes();
                if (null !== $attr) {
                    $payload = null;
                    $input = $attr->getInput();
                    if (null !== $input) {
                        $payloads = $input->getPayloads();
                        if ($payloads->count() > 0) {
                            $payload = JsonPlainPayload::decode($payloads[0]);
                        }
                    }
                    $this->signals[] = ['signalName' => $attr->getSignalName(), 'payload' => $payload];
                }
                break;

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED:
                $attr = $event->getWorkflowExecutionUpdateAcceptedEventAttributes();
                if (null !== $attr) {
                    $request = $attr->getAcceptedRequest();
                    if (null !== $request) {
                        $input = $request->getInput();
                        $updateName = null !== $input ? $input->getName() : '';
                        $this->updates[] = ['updateName' => $updateName, 'result' => null];
                    }
                }
                break;

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_COMPLETED:
                $attr = $event->getWorkflowExecutionUpdateCompletedEventAttributes();
                if (null !== $attr) {
                    $outcome = $attr->getOutcome();
                    if (null !== $outcome && null !== $outcome->getSuccess()) {
                        $payloads = $outcome->getSuccess()->getPayloads();
                        $result = $payloads->count() > 0 ? JsonPlainPayload::decode($payloads[0]) : null;
                        // Update the last update's result
                        $lastIdx = count($this->updates) - 1;
                        if ($lastIdx >= 0) {
                            $this->updates[$lastIdx]['result'] = $result;
                        }
                    }
                }
                break;

            case EventType::EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_INITIATED:
                $attr = $event->getStartChildWorkflowExecutionInitiatedEventAttributes();
                if (null !== $attr) {
                    $this->childExecutionIds[] = (string) $attr->getWorkflowId();
                }
                break;

            case EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_COMPLETED:
                $attr = $event->getChildWorkflowExecutionCompletedEventAttributes();
                if (null !== $attr) {
                    $exec = $attr->getWorkflowExecution();
                    if (null !== $exec) {
                        $childId = $exec->getWorkflowId();
                        $result = null;
                        $resultPayloads = $attr->getResult();
                        if (null !== $resultPayloads) {
                            $payloads = $resultPayloads->getPayloads();
                            if ($payloads->count() > 0) {
                                $result = JsonPlainPayload::decode($payloads[0]);
                            }
                        }
                        $this->childOutcomes[$childId] = ['result' => $result, 'failed' => false];
                    }
                }
                break;

            case EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_FAILED:
                $attr = $event->getChildWorkflowExecutionFailedEventAttributes();
                if (null !== $attr) {
                    $exec = $attr->getWorkflowExecution();
                    if (null !== $exec) {
                        $childId = $exec->getWorkflowId();
                        $this->childOutcomes[$childId] = ['result' => null, 'failed' => true];
                    }
                }
                break;
        }
    }

    public function findActivitySlotResult(int $slot): ?array
    {
        $activityId = $this->scheduledActivityIds[$slot] ?? null;
        if (null === $activityId) {
            return null;
        }

        if (isset($this->activityFailures[$activityId])) {
            return ['result' => null, 'failed' => $this->activityFailures[$activityId]];
        }
        if (isset($this->activityCancellations[$activityId])) {
            return ['result' => null, 'failed' => new ActivitySupersededException($activityId, $this->activityCancellations[$activityId])];
        }
        if (\array_key_exists($activityId, $this->activityResults)) {
            return ['result' => $this->activityResults[$activityId], 'failed' => null];
        }

        return null;
    }

    public function findScheduledActivityId(int $slot): ?string
    {
        return $this->scheduledActivityIds[$slot] ?? null;
    }

    public function findTimerSlotResult(int $slot): ?array
    {
        $timerId = $this->scheduledTimerIds[$slot] ?? null;
        if (null === $timerId || !isset($this->firedTimerIds[$timerId])) {
            return null;
        }

        return ['id' => $timerId, 'scheduledAt' => $this->timerScheduledAt[$timerId] ?? 0.0];
    }

    public function findScheduledTimerId(int $slot): ?string
    {
        return $this->scheduledTimerIds[$slot] ?? null;
    }

    public function findSideEffectForSlot(int $slot): mixed
    {
        return $this->sideEffects[$slot] ?? null;
    }

    public function findChildWorkflowForSlot(int $slot): ?array
    {
        $childId = $this->childExecutionIds[$slot] ?? null;
        if (null === $childId) {
            return null;
        }

        $outcome = $this->childOutcomes[$childId] ?? null;
        if (null === $outcome) {
            return null;
        }

        if ($outcome['failed']) {
            return [
                'childExecutionId' => $childId,
                'result' => null,
                'failed' => new \RuntimeException('Child workflow failed'),
            ];
        }

        return ['childExecutionId' => $childId, 'result' => $outcome['result'], 'failed' => null];
    }

    public function findScheduledChildExecutionId(int $slot): ?string
    {
        return $this->childExecutionIds[$slot] ?? null;
    }

    public function findSignalForSlot(string $signalName, int $slot): ?array
    {
        $index = 0;
        foreach ($this->signals as $signal) {
            if ($signal['signalName'] === $signalName) {
                if ($index === $slot) {
                    return ['payload' => $signal['payload']];
                }
                ++$index;
            }
        }

        return null;
    }

    public function findUpdateForSlot(string $updateName, int $slot): ?array
    {
        $index = 0;
        foreach ($this->updates as $update) {
            if ($update['updateName'] === $updateName) {
                if ($index === $slot) {
                    return ['result' => $update['result']];
                }
                ++$index;
            }
        }

        return null;
    }

    public function hasChildExecutionId(string $childExecutionId): bool
    {
        return \in_array($childExecutionId, $this->childExecutionIds, true);
    }

    public function hasChildExecutionCompletedSuccessfully(string $childExecutionId): bool
    {
        $outcome = $this->childOutcomes[$childExecutionId] ?? null;

        return null !== $outcome && !$outcome['failed'];
    }

    public function durableExecutionId(): ?string
    {
        return $this->durableExecutionId;
    }

    /**
     * @return array<string, mixed>
     */
    public function startInput(): array
    {
        return $this->startInput;
    }
}
