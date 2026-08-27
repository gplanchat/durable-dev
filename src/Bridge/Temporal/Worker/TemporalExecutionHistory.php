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
use Gplanchat\Durable\Nexus\NexusAsynchronousOperationUnsupportedException;
use Gplanchat\Durable\Nexus\NexusOperationFailureKind;
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

    /** @var list<string> identités d'opérations Nexus, dans l'ordre de planification */
    /** @var list<string> */
    private array $childWorkflowTypes = [];

    private array $scheduledNexusOperationIds = [];

    /** @var array<string, int> identité applicative → eventId du NEXUS_OPERATION_SCHEDULED */
    private array $nexusOperationToScheduledEventId = [];

    /** @var array<int, array{operationId: string, endpoint: string, service: string, operation: string}> */
    private array $nexusOperationCallSites = [];

    /** @var array<int, array{result: mixed, failed: \Throwable|null}> eventId de planification → issue */
    private array $nexusOperationOutcomes = [];

    /** @var array<string, int> activity ID → scheduled event ID */
    private array $activityIdToScheduledEventId = [];

    /** @var array<string, string> activityId → nom d'activité (pour typer les échecs) */
    private array $activityNames = [];

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

    /** @var array<string, int> timer ID → eventId of its TIMER_FIRED (l'ordre du journal tranche le verdict d'une échéance) */
    private array $firedTimerIds = [];

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

    /** Cause du WORKFLOW_EXECUTION_CANCEL_REQUESTED, si le serveur en a enregistré un. */
    private ?string $cancelRequestedCause = null;

    public const MARKER_SIDE_EFFECT = 'SideEffect';

    public const MARKER_CANCELLATION_DELIVERED = 'WorkflowCancellationDelivered';

    /** @var array<string, true> identifiants d'opérations retirées par l'annulation du workflow */
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
                    // Temporal n'a pas de champ d'identité applicative pour une opération Nexus :
                    // le tampon la glisse dans le payload d'entrée, et c'est là qu'on la relit.
                    $decoded = null !== $attr->getInput() ? JsonPlainPayload::decode($attr->getInput()) : null;
                    $operationId = \is_array($decoded) ? (string) ($decoded['operationId'] ?? '') : '';
                    if ('' !== $operationId) {
                        $this->scheduledNexusOperationIds[] = $operationId;
                        $this->nexusOperationToScheduledEventId[$operationId] = (int) $eventId;
                        // Le site d'appel n'est écrit qu'ici : les événements terminaux ne portent
                        // que l'eventId. Sans le retenir, un échec ne pourrait pas dire d'où il
                        // vient, et le spec l'exige.
                        $this->nexusOperationCallSites[(int) $eventId] = [
                            'operationId' => $operationId,
                            'endpoint' => (string) $attr->getEndpoint(),
                            'service' => (string) $attr->getService(),
                            'operation' => (string) $attr->getOperation(),
                        ];
                    }
                }
                break;

            case EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED:
                $attr = $event->getNexusOperationStartedEventAttributes();
                // Sans jeton, l'opération est synchrone : elle a démarré et répondra sur cette
                // exécution, il n'y a rien à signaler. Avec un jeton, le handler annonce qu'il
                // répondra par rappel — et rien ici ne sait le recevoir (§4.5).
                if (null !== $attr && '' !== (string) $attr->getOperationToken()) {
                    $scheduledEventId = (int) $attr->getScheduledEventId();
                    $operationId = array_search($scheduledEventId, $this->nexusOperationToScheduledEventId, true);
                    $this->nexusOperationOutcomes[$scheduledEventId] = [
                        'result' => null,
                        'failed' => NexusAsynchronousOperationUnsupportedException::forOperation(
                            false === $operationId ? (string) $scheduledEventId : $operationId,
                        ),
                    ];
                }
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
                        // Un RuntimeException nu était relevé dans le fiber : le classifieur le
                        // rangeait en workflow_handler_failure, et le workflow perdait le nom de
                        // l'activité fautive — là où le backend in-memory relève un
                        // DurableActivityFailedException complet.
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
                // Filtrer sur le nom : sans ça, TOUT marqueur consommait un slot de side effect
                // et décalait le replay de tous les suivants.
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
                        // `accepted_request` réécho la requête d'origine : les arguments sont
                        // donc relisibles au replay, comme la charge utile d'un signal.
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
                    // Le type, en parallèle et au même index : c'est lui l'identité du slot,
                    // l'identifiant d'exécution étant engendré.
                    $this->childWorkflowTypes[] = (string) ($attr->getWorkflowType()?->getName() ?? '');
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

        // Prioritaire sur tout le reste : une fois l'annulation livrée pour cette opération,
        // elle doit se relire à l'identique, même si le serveur a finalement enregistré une
        // complétion arrivée entre-temps.
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

    public function activityNameForSlot(int $slot): ?string
    {
        $activityId = $this->scheduledActivityIds[$slot] ?? null;
        if (null === $activityId) {
            return null;
        }

        // L'indexation ci-dessus ramène un type d'activité absent à la chaîne vide. Une chaîne
        // vide n'est pas un nom : c'est « l'historique n'a rien dit ». La rendre telle quelle
        // ferait diverger tout slot dont le type manque, ce qui est le contraire d'une garde.
        $name = $this->activityNames[$activityId] ?? '';

        return '' === $name ? null : $name;
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
        // Deux tableaux séparés côté Temporal, un seul ordre côté workflow : la fusion se fait
        // par eventId, sinon tous les signaux passeraient avant tous les updates.
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
     * Cause de l'annulation demandée par le serveur, ou null si aucune ne l'a été.
     */
    /**
     * Identifiant de l'événement ACTIVITY_TASK_SCHEDULED de cette activité, ou null si elle n'a
     * pas encore été planifiée dans l'historique.
     *
     * Attendu par {@code RequestCancelActivityTaskCommandAttributes::scheduledEventId} : un
     * identifiant qui ne correspond à aucun événement fait rejeter la tâche par le serveur.
     */
    public function scheduledEventIdForActivity(string $activityId): ?int
    {
        return $this->activityIdToScheduledEventId[$activityId] ?? null;
    }

    /**
     * `RecordMarkerCommandAttributes::details` est une map<string, Payloads> : la valeur est une
     * enveloppe, pas un Payload.
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
     * Vrai si l'annulation a déjà été relevée dans le fiber lors d'une tâche antérieure : au
     * rejeu, c'est le rejet des opérations retirées qui la reporte, pas une nouvelle livraison.
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
     * L'échec typé d'une opération, avec sa nature et son site d'appel.
     *
     * §3.6 avait construit l'exception, §4.3 lisait les événements, et rien ne reliait les deux :
     * la lecture rendait des `RuntimeException` nues, si bien que la branche Nexus du
     * classificateur ne pouvait jamais se déclencher. Un workflow tombé sur une opération ne
     * disait donc pas laquelle.
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
     * L'issue enregistrée de l'opération au slot N, ou null tant qu'elle est en vol.
     *
     * « Planifiée » n'est pas « réglée », et les confondre ferait conclure le workflow sur une
     * opération qui n'a pas répondu.
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
     * L'identité de l'opération planifiée au slot N, ou null si ce slot n'a rien.
     *
     * C'est ce qui empêche le replay de replanifier : le contexte n'émet la commande que si le
     * slot est vide. Rendre `null` sans lire l'historique relancerait l'opération à chaque passe,
     * en silence — et une opération Nexus qui repart est facturée à chaque fois.
     */
    public function findScheduledNexusOperation(int $slot): ?string
    {
        return $this->scheduledNexusOperationIds[$slot] ?? null;
    }

    /**
     * L'eventId du `NEXUS_OPERATION_SCHEDULED` de cette opération, ou null.
     *
     * Attendu par `RequestCancelNexusOperationCommandAttributes` (§4.2) : un identifiant qui ne
     * correspond à aucun événement fait rejeter la tâche par le serveur.
     */
    public function scheduledEventIdForNexusOperation(string $operationId): ?int
    {
        return $this->nexusOperationToScheduledEventId[$operationId] ?? null;
    }
}
