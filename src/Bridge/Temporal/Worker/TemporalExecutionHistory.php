<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableNexusOperationFailedException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Nexus\NexusOperationFailureKind;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use Gplanchat\Durable\Versioning\ChangePoint;
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

    /** @var list<string> Nexus operation identities, in schedule order */
    /** @var list<string> */
    private array $childWorkflowTypes = [];

    /** @var array<string, int> */
    private array $versionsByChangeId = [];

    private array $scheduledNexusOperationIds = [];

    /** @var array<string, int> application identity → eventId of the NEXUS_OPERATION_SCHEDULED */
    private array $nexusOperationToScheduledEventId = [];

    /** @var array<int, array{operationId: string, endpoint: string, service: string, operation: string}> */
    private array $nexusOperationCallSites = [];

    /** @var array<int, array{result: mixed, failed: \Throwable|null}> scheduling eventId → outcome */
    private array $nexusOperationOutcomes = [];

    /** @var array<string, int> activity ID → scheduled event ID */
    private array $activityIdToScheduledEventId = [];

    /** @var array<string, string> activityId → activity name (to type the failures) */
    private array $activityNames = [];

    /** @var array<string, array<string, mixed>> activityId → charge planifiée (garde DUR042) */
    private array $activityPayloads = [];

    /** @var array<int, array<string, mixed>> slot → charge Nexus planifiée (garde DUR042) */
    private array $nexusOperationPayloads = [];

    /** @var array<int, array<string, mixed>> slot → input du workflow enfant (garde DUR042) */
    private array $childWorkflowInputs = [];

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

    /** @var array<string, int> timer ID → eventId of its TIMER_FIRED (the journal order settles a deadline's verdict) */
    private array $firedTimerIds = [];

    /** @var array<string, true> timers the workflow cancelled itself (losers of a race) */
    private array $cancelledTimerIds = [];

    /** @var array<int, mixed> slot index → side effect result (MARKER_RECORDED events) */
    private array $sideEffects = [];

    /** @var list<array{signalName: string, payload: mixed, eventId: int}> signals in receive order */
    private array $signals = [];

    /** @var list<array{updateName: string, result: mixed, eventId: int, arguments: array<string, mixed>}> updates in accept order */
    private array $updates = [];

    /** @var list<string> child execution IDs in schedule order */
    private array $childExecutionIds = [];

    /** @var array<string, array{result: mixed, failed: bool}> child execution ID → outcome */
    private array $childOutcomes = [];

    private int $sideEffectSlot = 0;

    private ?string $durableExecutionId = null;

    /** @var array<string, mixed> */
    private array $startInput = [];

    /** Cause of the WORKFLOW_EXECUTION_CANCEL_REQUESTED, if the server recorded one. */
    private ?string $cancelRequestedCause = null;

    public const MARKER_SIDE_EFFECT = 'SideEffect';

    public const MARKER_CANCELLATION_DELIVERED = 'WorkflowCancellationDelivered';

    /** @var array<string, true> ids of the operations withdrawn by the workflow cancellation */
    private array $cancellationDeliveredTargets = [];

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

            case EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED:
                $attr = $event->getNexusOperationScheduledEventAttributes();
                if (null !== $attr) {
                    // Temporal has no application identity field for a Nexus operation: the
                    // buffer slips it into the input payload, and that is where it is read back.
                    // A Nexus operation's identity is the eventId the server assigns, and not an
                    // id the caller would slip into the payload: the payload belongs to the user,
                    // and a handler from another SDK looks for its own fields in it.
                    $operationId = (string) $eventId;
                    if ('' !== $operationId) {
                        $this->scheduledNexusOperationIds[] = $operationId;
                        $this->nexusOperationToScheduledEventId[$operationId] = (int) $eventId;
                        // The call site is written only here: the terminal events carry nothing
                        // but the eventId. Without keeping it, a failure could not say where it
                        // comes from, and the spec demands it.
                        $this->nexusOperationCallSites[(int) $eventId] = [
                            'operationId' => $operationId,
                            'endpoint' => (string) $attr->getEndpoint(),
                            'service' => (string) $attr->getService(),
                            'operation' => (string) $attr->getOperation(),
                        ];

                        // La charge de l'appelant, **nue** : une opération Nexus porte un
                        // `Payload` et non des `Payloads`, et l'enveloppe `{operationId, payload}`
                        // a été retirée du tampon (tâche 1.1). Décoder autre chose ici comparerait
                        // une forme que le fil ne porte plus.
                        $nexusInput = $attr->getInput();
                        if (null !== $nexusInput) {
                            $decodedInput = JsonPlainPayload::decode($nexusInput);
                            if (\is_array($decodedInput)) {
                                $this->nexusOperationPayloads[\count($this->scheduledNexusOperationIds) - 1] = $decodedInput;
                            }
                        }
                    }
                }
                break;

            case EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED:
                // Nothing to do. The token says the handler will answer later, by callback; the
                // 1.4 probe measured that the server sets `callback: temporal://system` and
                // itself correlates the outcome onto this execution, by `scheduledEventId`. The
                // wait therefore stays open until the terminal event, which the branches below
                // read. Recording an outcome here — even an "unsupported" failure — would kill a
                // workflow on an operation that was about to answer.
                break;

            case EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED:
                $attr = $event->getNexusOperationCompletedEventAttributes();
                if (null !== $attr) {
                    $result = null;
                    $payload = $attr->getResult();
                    if (null !== $payload) {
                        $result = JsonPlainPayload::decode($payload);
                    }
                    $this->nexusOperationOutcomes[(int) $attr->getScheduledEventId()] = ['result' => $result, 'failed' => null];
                }
                break;

            case EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED:
                $attr = $event->getNexusOperationFailedEventAttributes();
                if (null !== $attr) {
                    $this->nexusOperationOutcomes[(int) $attr->getScheduledEventId()] = [
                        'result' => null,
                        'failed' => $this->nexusFailure((int) $attr->getScheduledEventId(), NexusOperationFailureKind::OperationFailed),
                    ];
                }
                break;

            case EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT:
                $attr = $event->getNexusOperationTimedOutEventAttributes();
                if (null !== $attr) {
                    $this->nexusOperationOutcomes[(int) $attr->getScheduledEventId()] = [
                        'result' => null,
                        'failed' => $this->nexusFailure((int) $attr->getScheduledEventId(), NexusOperationFailureKind::Timeout),
                    ];
                }
                break;

            case EventType::EVENT_TYPE_NEXUS_OPERATION_CANCELED:
                $attr = $event->getNexusOperationCanceledEventAttributes();
                if (null !== $attr) {
                    $this->nexusOperationOutcomes[(int) $attr->getScheduledEventId()] = [
                        'result' => null,
                        'failed' => $this->nexusFailure((int) $attr->getScheduledEventId(), NexusOperationFailureKind::Cancellation),
                    ];
                }
                break;

            case EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED:
                $attr = $event->getActivityTaskScheduledEventAttributes();
                if (null !== $attr) {
                    $activityId = (string) $attr->getActivityId();
                    $this->scheduledActivityIds[] = $activityId;
                    $this->activityIdToScheduledEventId[$activityId] = $eventId;
                    $this->activityNames[$activityId] = (string) ($attr->getActivityType()?->getName() ?? '');
                    $this->scheduledEventIdToActivityId[$eventId] = $activityId;

                    // L'entrée porte l'enveloppe écrite par TemporalActivityScheduleInput ; sa case
                    // `payload` tient les arguments. Absente ou illisible, on n'enregistre rien :
                    // la garde n'a alors rien à comparer, ce qui est son cas de repos.
                    $input = $attr->getInput();
                    if (null !== $input) {
                        $payloads = $input->getPayloads();
                        if ($payloads->count() > 0) {
                            $envelope = JsonPlainPayload::decode($payloads[0]);
                            if (\is_array($envelope) && \is_array($envelope['payload'] ?? null)) {
                                $this->activityPayloads[$activityId] = $envelope['payload'];
                            }
                        }
                    }
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
                        $type = $failure?->getApplicationFailureInfo()?->getType();
                        // A bare RuntimeException used to be raised in the fiber: the classifier
                        // filed it under workflow_handler_failure, and the workflow lost the name
                        // of the faulty activity — where the in-memory backend raises a complete
                        // DurableActivityFailedException.
                        $this->activityFailures[$activityId] = new DurableActivityFailedException(
                            $activityId,
                            $this->activityNames[$activityId] ?? '',
                            1,
                            new FailureEnvelope(
                                \is_string($type) && '' !== $type ? $type : \RuntimeException::class,
                                $message,
                                0,
                                [],
                                null !== $failure ? $failure->getStackTrace() : null,
                                [],
                            ),
                        );
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
                        $this->firedTimerIds[$timerId] = (int) $eventId;
                    }
                }
                break;

            case EventType::EVENT_TYPE_TIMER_CANCELED:
                $attr = $event->getTimerCanceledEventAttributes();
                if (null !== $attr) {
                    $this->cancelledTimerIds[(string) $attr->getTimerId()] = true;
                }
                break;

            case EventType::EVENT_TYPE_MARKER_RECORDED:
                $attr = $event->getMarkerRecordedEventAttributes();
                if (null !== $attr && self::MARKER_CANCELLATION_DELIVERED === $attr->getMarkerName()) {
                    $details = $attr->getDetails();
                    $targetsPayload = null !== $details && $details->offsetExists('targets')
                        ? $details->offsetGet('targets')
                        : null;
                    $targets = null !== $targetsPayload ? self::decodeMarkerDetail($targetsPayload) : null;
                    foreach (\is_array($targets) ? $targets : [] as $target) {
                        $this->cancellationDeliveredTargets[(string) $target] = true;
                    }
                    break;
                }
                // The version marker, before the side-effect one and for the reason the next
                // comment gives: without a branch of its own, it would consume a side-effect
                // slot and shift the replay of every following one.
                if (null !== $attr && ChangePoint::MARKER_NAME === $attr->getMarkerName()) {
                    $details = $attr->getDetails();
                    $changeIdPayload = null !== $details && $details->offsetExists(ChangePoint::DETAIL_CHANGE_ID)
                        ? $details->offsetGet(ChangePoint::DETAIL_CHANGE_ID)
                        : null;
                    $versionPayload = null !== $details && $details->offsetExists(ChangePoint::DETAIL_VERSION)
                        ? $details->offsetGet(ChangePoint::DETAIL_VERSION)
                        : null;
                    if (null !== $changeIdPayload && null !== $versionPayload) {
                        $this->versionsByChangeId[(string) self::decodeMarkerDetail($changeIdPayload)]
                            = (int) self::decodeMarkerDetail($versionPayload);
                    }
                    break;
                }
                // Filter on the name: without this, EVERY marker consumed a side effect slot
                // and shifted the replay of every following one.
                if (null !== $attr && self::MARKER_SIDE_EFFECT === $attr->getMarkerName()) {
                    $details = $attr->getDetails();
                    $resultPayload = null;
                    if (null !== $details && $details->offsetExists('result')) {
                        $resultPayload = $details->offsetGet('result');
                    }
                    $result = null !== $resultPayload ? self::decodeMarkerDetail($resultPayload) : null;
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
                    $this->signals[] = ['signalName' => $attr->getSignalName(), 'payload' => $payload, 'eventId' => (int) $eventId];
                }
                break;

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED:
                $attr = $event->getWorkflowExecutionUpdateAcceptedEventAttributes();
                if (null !== $attr) {
                    $request = $attr->getAcceptedRequest();
                    if (null !== $request) {
                        $input = $request->getInput();
                        $updateName = null !== $input ? $input->getName() : '';
                        // `accepted_request` echoes the original request back: the arguments are
                        // therefore readable again on replay, like a signal's payload.
                        $arguments = [];
                        $args = $input?->getArgs()?->getPayloads();
                        if (null !== $args && $args->count() > 0) {
                            $decoded = JsonPlainPayload::decode($args[0]);
                            $arguments = \is_array($decoded) ? $decoded : ['value' => $decoded];
                        }
                        $this->updates[] = ['updateName' => $updateName, 'result' => null, 'eventId' => (int) $eventId, 'arguments' => $arguments];
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
                    // The type, in parallel and at the same index: it is the slot's identity,
                    // the execution id being generated.
                    $this->childWorkflowTypes[] = (string) ($attr->getWorkflowType()?->getName() ?? '');

                    // `singlePayloads(encode($input))` côté tampon : une liste d'un élément, dont
                    // le premier est l'input nu. Une forme de plus que Nexus (Payload nu) et que
                    // l'activité (enveloppe) — les trois se ressemblent et ne se valent pas.
                    $childInput = $attr->getInput();
                    if (null !== $childInput) {
                        $childPayloads = $childInput->getPayloads();
                        if ($childPayloads->count() > 0) {
                            $decodedChild = JsonPlainPayload::decode($childPayloads[0]);
                            if (\is_array($decodedChild)) {
                                $this->childWorkflowInputs[\count($this->childExecutionIds) - 1] = $decodedChild;
                            }
                        }
                    }
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

            case EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCEL_REQUESTED:
                $attr = $event->getWorkflowExecutionCancelRequestedEventAttributes();
                $cause = null !== $attr ? (string) $attr->getCause() : '';
                $this->cancelRequestedCause = '' !== $cause ? $cause : 'cancel_requested';
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

        // Takes priority over everything else: once the cancellation is delivered for this
        // operation, it must read back identically, even if the server ended up recording a
        // completion that arrived in the meantime.
        if (isset($this->cancellationDeliveredTargets[$activityId])) {
            return ['result' => null, 'failed' => new WorkflowCancelledFailure($this->durableExecutionId() ?? '', ActivityCancellationReason::WORKFLOW_CANCELLED)];
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

    public function versionForChangeId(string $changeId): ?int
    {
        return $this->versionsByChangeId[$changeId] ?? null;
    }

    public function activityNameForSlot(int $slot): ?string
    {
        $activityId = $this->scheduledActivityIds[$slot] ?? null;
        if (null === $activityId) {
            return null;
        }

        // The indexing above brings a missing activity type down to the empty string. An empty
        // string is not a name: it is "the history said nothing". Returning it as-is would make
        // every slot whose type is missing diverge, which is the opposite of a guard.
        $name = $this->activityNames[$activityId] ?? '';

        return '' === $name ? null : $name;
    }

    public function nexusOperationPayloadForSlot(int $slot): ?array
    {
        return $this->nexusOperationPayloads[$slot] ?? null;
    }

    public function childWorkflowInputForSlot(int $slot): ?array
    {
        return $this->childWorkflowInputs[$slot] ?? null;
    }

    public function activityPayloadForSlot(int $slot): ?array
    {
        $activityId = $this->scheduledActivityIds[$slot] ?? null;
        if (null === $activityId) {
            return null;
        }

        return $this->activityPayloads[$activityId] ?? null;
    }

    public function findTimerSlotResult(int $slot): ?array
    {
        $timerId = $this->scheduledTimerIds[$slot] ?? null;
        if (null === $timerId) {
            return null;
        }
        if (isset($this->cancellationDeliveredTargets[$timerId])) {
            return [
                'id' => $timerId,
                'scheduledAt' => $this->timerScheduledAt[$timerId] ?? 0.0,
                'failed' => new WorkflowCancelledFailure($this->durableExecutionId() ?? '', ActivityCancellationReason::WORKFLOW_CANCELLED),
            ];
        }
        if (!isset($this->firedTimerIds[$timerId])) {
            return null;
        }

        return ['id' => $timerId, 'scheduledAt' => $this->timerScheduledAt[$timerId] ?? 0.0, 'failed' => null];
    }

    /**
     * Does the timer already have an outcome in the history — fired or cancelled?
     *
     * A cancelled timer stays absent from {@see findTimerSlotResult()}: the loser of a race has
     * no verdict to announce, so it comes back to waiting on every resume. Without this guard,
     * the workflow would re-emit `CANCEL_TIMER` on every task.
     */
    public function isTimerSettled(string $timerId): bool
    {
        return isset($this->cancelledTimerIds[$timerId]) || isset($this->firedTimerIds[$timerId]);
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

    public function childWorkflowTypeForSlot(int $slot): ?string
    {
        $type = $this->childWorkflowTypes[$slot] ?? '';

        return '' === $type ? null : $type;
    }

    public function nexusOperationSignatureForSlot(int $slot): ?string
    {
        $operationId = $this->scheduledNexusOperationIds[$slot] ?? null;
        if (null === $operationId) {
            return null;
        }

        $eventId = $this->nexusOperationToScheduledEventId[$operationId] ?? null;
        $site = null === $eventId ? null : ($this->nexusOperationCallSites[$eventId] ?? null);
        if (null === $site) {
            return null;
        }

        return \sprintf('%s/%s/%s', $site['endpoint'], $site['service'], $site['operation']);
    }

    public function messageAt(int $index): ?array
    {
        // Two separate arrays on the Temporal side, a single order on the workflow side: the
        // merge is done by eventId, otherwise every signal would come before every update.
        $messages = [];
        foreach ($this->signals as $signal) {
            $messages[] = [
                'position' => $signal['eventId'],
                'kind' => 'signal',
                'name' => $signal['signalName'],
                'payload' => \is_array($signal['payload']) ? $signal['payload'] : ['value' => $signal['payload']],
            ];
        }
        foreach ($this->updates as $update) {
            $messages[] = [
                'position' => $update['eventId'],
                'kind' => 'update',
                'name' => $update['updateName'],
                'payload' => $update['arguments'],
            ];
        }
        usort($messages, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);

        return $messages[$index] ?? null;
    }

    public function timerCompletionPosition(string $timerId): ?int
    {
        return $this->firedTimerIds[$timerId] ?? null;
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
    /**
     * Cause of the cancellation the server requested, or null if none was requested.
     */
    /**
     * Id of this activity's ACTIVITY_TASK_SCHEDULED event, or null if it has not been scheduled
     * in the history yet.
     *
     * Expected by {@code RequestCancelActivityTaskCommandAttributes::scheduledEventId}: an id
     * that matches no event makes the server reject the task.
     */
    public function scheduledEventIdForActivity(string $activityId): ?int
    {
        return $this->activityIdToScheduledEventId[$activityId] ?? null;
    }

    /**
     * `RecordMarkerCommandAttributes::details` is a map<string, Payloads>: the value is an
     * envelope, not a Payload.
     */
    private static function decodeMarkerDetail(\Temporal\Api\Common\V1\Payloads $detail): mixed
    {
        $payloads = $detail->getPayloads();

        return $payloads->count() > 0 ? JsonPlainPayload::decode($payloads[0]) : null;
    }

    public function cancellationRequestedCause(): ?string
    {
        return $this->cancelRequestedCause;
    }

    /**
     * True if the cancellation was already raised in the fiber during an earlier task: on
     * replay, it is the rejection of the withdrawn operations that carries it over, not a new
     * delivery.
     */
    public function cancellationAlreadyDelivered(): bool
    {
        return [] !== $this->cancellationDeliveredTargets;
    }

    public function startInput(): array
    {
        return $this->startInput;
    }

    /**
     * The typed failure of an operation, with its kind and its call site.
     *
     * §3.6 had built the exception, §4.3 read the events, and nothing linked the two: the reading
     * returned bare `RuntimeException`s, so that the classifier's Nexus branch could never fire.
     * A workflow brought down on an operation therefore did not say which one.
     */
    private function nexusFailure(int $scheduledEventId, NexusOperationFailureKind $kind): DurableNexusOperationFailedException
    {
        $site = $this->nexusOperationCallSites[$scheduledEventId] ?? null;

        return new DurableNexusOperationFailedException(
            $site['endpoint'] ?? '',
            $site['service'] ?? '',
            $site['operation'] ?? '',
            $kind,
            new FailureEnvelope(self::class, \sprintf('Nexus operation ended as %s', $kind->value)),
        );
    }

    /**
     * The recorded outcome of the operation at slot N, or null while it is in flight.
     *
     * "Scheduled" is not "settled", and confusing the two would make the workflow conclude on an
     * operation that has not answered.
     *
     * @return array{result: mixed, failed: \Throwable|null}|null
     */
    public function findNexusOperationSlotResult(int $slot): ?array
    {
        $operationId = $this->scheduledNexusOperationIds[$slot] ?? null;
        if (null === $operationId) {
            return null;
        }

        $scheduledEventId = $this->nexusOperationToScheduledEventId[$operationId] ?? null;

        return null === $scheduledEventId ? null : ($this->nexusOperationOutcomes[$scheduledEventId] ?? null);
    }

    /**
     * The identity of the operation scheduled at slot N, or null if that slot has nothing.
     *
     * This is what stops replay from rescheduling: the context only emits the command if the slot
     * is empty. Returning `null` without reading the history would restart the operation on every
     * pass, in silence — and a Nexus operation that starts again is billed every time.
     */
    public function findScheduledNexusOperation(int $slot): ?string
    {
        return $this->scheduledNexusOperationIds[$slot] ?? null;
    }

    /**
     * The eventId of this operation's `NEXUS_OPERATION_SCHEDULED`, or null.
     *
     * Expected by `RequestCancelNexusOperationCommandAttributes` (§4.2): an id that matches no
     * event makes the server reject the task.
     */
    public function scheduledEventIdForNexusOperation(string $operationId): ?int
    {
        return $this->nexusOperationToScheduledEventId[$operationId] ?? null;
    }
}
