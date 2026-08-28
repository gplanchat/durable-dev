<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Store;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;

/**
 * Traduit l'historique Temporal en événements lisibles, dans le vocabulaire du composant.
 *
 * L'ordre des tests de rangement compte, et c'est le piège que le code d'origine n'évitait pas :
 * `WORKFLOW_EXECUTION_SIGNALED` contient `WORKFLOW_`, et
 * `START_CHILD_WORKFLOW_EXECUTION_INITIATED` aussi. Chercher `WORKFLOW_` en premier range donc les
 * signaux et les workflows enfants sur la voie de l'exécution. Les cas particuliers passent avant
 * le cas général.
 */
final class TemporalRunHistoryReader
{
    public function __construct(
        private readonly TemporalHistoryCursor $cursor,
    ) {}

    /**
     * @return list<WorkflowRunEvent>
     */
    public function read(string $workflowId, string $runId): array
    {
        $execution = new WorkflowExecution();
        $execution->setWorkflowId($workflowId);
        $execution->setRunId($runId);

        $history = [];
        foreach ($this->cursor->events($execution) as $event) {
            $type = EventType::name($event->getEventType());

            $history[] = new WorkflowRunEvent(
                (int) $event->getEventId(),
                self::recordedAt($event),
                self::kindOf($type),
                self::labelOf($event, $type),
            );
        }

        return $history;
    }

    private static function kindOf(string $eventType): WorkflowRunEventKind
    {
        return match (true) {
            // Avant tout le reste : NEXUS_OPERATION_CANCEL_REQUESTED contient CANCEL, et une
            // règle placée plus bas laisserait passer les variantes au fil des versions du serveur.
            str_contains($eventType, 'NEXUS_') => WorkflowRunEventKind::Nexus,
            str_contains($eventType, 'UPDATE_') => WorkflowRunEventKind::Update,
            str_contains($eventType, 'QUERY_') => WorkflowRunEventKind::Query,
            str_contains($eventType, 'SIGNAL') => WorkflowRunEventKind::Signal,
            str_contains($eventType, 'CHILD_WORKFLOW') => WorkflowRunEventKind::Other,
            str_contains($eventType, 'ACTIVITY_') => WorkflowRunEventKind::Activity,
            str_contains($eventType, 'WORKFLOW_') => WorkflowRunEventKind::Execution,
            default => WorkflowRunEventKind::Other,
        };
    }

    /**
     * Le nom métier d'abord, l'identifiant technique ensuite, le type d'événement en dernier
     * recours : `SendWelcomeEmail` vaut mieux que `act-1`, qui vaut mieux que
     * `ACTIVITY TASK SCHEDULED`.
     */
    private static function labelOf(HistoryEvent $event, string $eventType): string
    {
        $scheduled = $event->getActivityTaskScheduledEventAttributes();
        if (null !== $scheduled) {
            $name = (string) ($scheduled->getActivityType()?->getName() ?? '');
            if ('' !== $name) {
                return $name;
            }

            $activityId = (string) $scheduled->getActivityId();
            if ('' !== $activityId) {
                return $activityId;
            }
        }

        $signalled = $event->getWorkflowExecutionSignaledEventAttributes();
        if (null !== $signalled) {
            $name = (string) $signalled->getSignalName();
            if ('' !== $name) {
                return $name;
            }
        }

        return self::readableType($eventType);
    }

    private static function readableType(string $eventType): string
    {
        return str_replace('_', ' ', str_replace('EVENT_TYPE_', '', $eventType));
    }

    private static function recordedAt(HistoryEvent $event): \DateTimeImmutable
    {
        $time = $event->getEventTime();
        $seconds = null === $time ? 0 : $time->getSeconds();

        return (new \DateTimeImmutable('@' . $seconds))->setTimezone(new \DateTimeZone('UTC'));
    }
}
