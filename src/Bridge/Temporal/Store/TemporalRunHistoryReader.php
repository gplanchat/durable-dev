<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Store;

use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Temporal\Api\Common\V1\Payloads;
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
    /**
     * Les champs `Payloads` de l'historique, par leur nom dans la sérialisation JSON du message.
     *
     * @var array<string, string>
     */
    private const PAYLOAD_FIELDS = [
        'input' => 'getInput',
        'result' => 'getResult',
        'details' => 'getDetails',
        'lastHeartbeatDetails' => 'getLastHeartbeatDetails',
        'lastCompletionResult' => 'getLastCompletionResult',
    ];

    /**
     * La clé de l'action que l'exécution est pour elle-même. Une seule par historique, et la
     * première : son événement d'ouverture porte le numéro 1.
     */
    private const RUN_ACTION = 'workflow';

    /**
     * Les renvois vers l'événement fondateur d'une action, dans l'ordre où ils font autorité.
     *
     * @var list<string>
     */
    private const CORRELATION_GETTERS = [
        'getScheduledEventId',
        'getStartedEventId',
        'getInitiatedEventId',
    ];

    /**
     * Ce qui ouvre une action sans désigner personne : un minuteur démarré, une activité planifiée.
     *
     * @var list<string>
     */
    private const FOUNDING_SUFFIXES = ['_SCHEDULED', '_STARTED', '_INITIATED'];

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
                self::detailsOf($event),
                self::actionKeyOf($event, $type),
            );
        }

        return $history;
    }

    /**
     * L'action dont l'événement fait partie — celle dont il est le troisième acte, ou le premier.
     *
     * Temporal corrèle par **numéro d'événement** : tout ce qui arrive après une planification la
     * désigne par `scheduledEventId`, `startedEventId` ou `initiatedEventId` selon la famille. La
     * clé est donc l'événement fondateur, et les trois accesseurs se cherchent dans cet ordre —
     * une tâche de workflow terminée porte les deux premiers, et c'est la planification qui ouvre
     * l'action.
     *
     * Un événement fondateur qui ne désigne personne se désigne lui-même : sans quoi une activité
     * planifiée n'appartiendrait pas à l'action qu'elle ouvre.
     *
     * ⚠ `getParentInitiatedEventId` — que porte le démarrage d'une exécution enfant — n'est
     * volontairement pas dans la liste : il pointe vers l'histoire du **parent**, pas vers une
     * action de celle-ci. Le confondre rattacherait le démarrage à un numéro d'un autre journal.
     */
    private static function actionKeyOf(HistoryEvent $event, string $eventType): ?string
    {
        if (self::belongsToTheRunItself($eventType)) {
            return self::RUN_ACTION;
        }

        $which = $event->getAttributes();
        if ('' === $which) {
            return null;
        }

        $attributes = $event->{'get' . str_replace('_', '', ucwords($which, '_'))}();
        if (!$attributes instanceof Message) {
            return null;
        }

        foreach (self::CORRELATION_GETTERS as $getter) {
            if (!method_exists($attributes, $getter)) {
                continue;
            }

            $founder = (int) $attributes->{$getter}();
            if ($founder > 0) {
                return 'event:' . $founder;
            }
        }

        foreach (self::FOUNDING_SUFFIXES as $suffix) {
            if (str_ends_with($eventType, $suffix)) {
                return 'event:' . $event->getEventId();
            }
        }

        return null;
    }

    /**
     * L'exécution elle-même est **une action** : son démarrage, ses tâches de workflow, sa fin.
     *
     * Une tâche de workflow n'est pas un fait métier — c'est le mécanisme par lequel le moteur
     * fait avancer l'exécution. Lui donner une ligne par occurrence noyait les quatre lignes
     * intéressantes d'une commande sous quatre lignes de plomberie portant le même nom.
     *
     * ⚠ **Les exceptions sont l'essentiel de cette règle**, et le même piège que pour le rangement
     * en voies : `WORKFLOW_EXECUTION_SIGNALED` et la famille `WORKFLOW_EXECUTION_UPDATE_*`
     * commencent par le même préfixe et ne sont pas l'exécution — un signal reçu et une mise à jour
     * sont des actions à part entière, avec leur propre ligne. Les workflows **enfants**
     * (`CHILD_WORKFLOW_EXECUTION_*`, `START_CHILD_WORKFLOW_EXECUTION_*`) et les workflows
     * **externes** (`EXTERNAL_`, `REQUEST_CANCEL_EXTERNAL_`, `SIGNAL_EXTERNAL_`) ne commencent pas
     * par ce préfixe : c'est ce qui leur laisse leurs lignes, et c'est éprouvé type par type.
     */
    private static function belongsToTheRunItself(string $eventType): bool
    {
        if (str_starts_with($eventType, 'EVENT_TYPE_WORKFLOW_TASK_')) {
            return true;
        }

        if (!str_starts_with($eventType, 'EVENT_TYPE_WORKFLOW_EXECUTION_')) {
            return false;
        }

        return 'EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED' !== $eventType
            && !str_starts_with($eventType, 'EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_');
    }

    /**
     * Le contenu de l'événement, tel que le serveur le raconte.
     *
     * Les attributs sont un `oneof` : un seul des cinquante accesseurs répond, et le nom du champ
     * choisi se lit sur `getAttributes()`. La sérialisation JSON du message donne alors tout ce que
     * l'interface de Temporal montre elle-même — type d'activité, file, délais, tentative, message
     * d'échec — sans que nous ayons à énumérer les cinquante formes.
     *
     * ⚠ **Une charge utile y arriverait en base64**, parce que `Payload.data` est un champ `bytes`.
     * Les champs qui en portent sont donc relus par-dessus avec le codec du pont, celui-là même qui
     * les a écrites. Un `input` illisible dans un écran de diagnostic serait pire que pas d'écran.
     *
     * Un échec de sérialisation rend un tableau vide : un écran d'administration qui explose sur un
     * événement exotique n'apprend rien à personne, alors qu'une ligne sans détail reste une ligne.
     *
     * @return array<string, mixed>
     */
    private static function detailsOf(HistoryEvent $event): array
    {
        // `whichOneof` rend le nom du champ renseigné, ou la chaîne vide quand aucun ne l'est —
        // ce qui arrive pour un type d'événement plus récent que les stubs générés.
        $which = $event->getAttributes();
        if ('' === $which) {
            return [];
        }

        $attributes = $event->{'get' . str_replace('_', '', ucwords($which, '_'))}();
        if (!$attributes instanceof Message) {
            return [];
        }

        try {
            /** @var array<string, mixed> $details */
            $details = json_decode($attributes->serializeToJsonString(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        foreach (self::PAYLOAD_FIELDS as $field => $getter) {
            if (!method_exists($attributes, $getter)) {
                continue;
            }

            $payloads = $attributes->{$getter}();
            if (!$payloads instanceof Payloads) {
                continue;
            }

            try {
                $details[$field] = JsonPlainPayload::decodePayloads($payloads);
            } catch (\Throwable) {
                // Une charge utile qui n'est pas du `json/plain` — un autre encodage, un autre
                // producteur. La forme base64 de la sérialisation reste en place : moins lisible,
                // mais présente, et c'est encore la meilleure des deux réponses possibles.
            }
        }

        return $details;
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
        // Une ligne de frise porte le nom de son action. Les événements qui ouvrent une exécution —
        // la sienne, celle d'un enfant — nomment leur type de workflow, et c'est ce nom-là que
        // l'exploitant cherche, pas « WORKFLOW EXECUTION STARTED » en capitales.
        $workflowType = self::workflowTypeOf($event);
        if (null !== $workflowType) {
            return $workflowType;
        }

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

    /**
     * Le type de workflow que l'événement nomme, s'il en nomme un.
     *
     * `getWorkflowType()` est porté par le démarrage d'une exécution comme par les événements d'un
     * workflow enfant — une seule règle nomme donc la ligne de l'exécution et celles de ses
     * enfants, sans table de correspondance à tenir.
     */
    private static function workflowTypeOf(HistoryEvent $event): ?string
    {
        $which = $event->getAttributes();
        if ('' === $which) {
            return null;
        }

        $attributes = $event->{'get' . str_replace('_', '', ucwords($which, '_'))}();
        if (!$attributes instanceof Message || !method_exists($attributes, 'getWorkflowType')) {
            return null;
        }

        $name = (string) ($attributes->getWorkflowType()?->getName() ?? '');

        return '' === $name ? null : $name;
    }

    private static function readableType(string $eventType): string
    {
        return str_replace('_', ' ', str_replace('EVENT_TYPE_', '', $eventType));
    }

    /**
     * ⚠ **Les nanosecondes ne sont pas décoratives.** Temporal horodate à la nanoseconde, et n'en
     * garder que les secondes écrasait tout ce qu'une exécution rapide a fait : seize événements
     * séparés de quelques millisecondes se lisaient au même instant. Une frise construite là-dessus
     * empile tous ses repères au même endroit et ne dit plus rien. PHP s'arrête à la microseconde,
     * donc c'est là que la troncature a lieu — assumée, et six ordres de grandeur plus bas.
     */
    private static function recordedAt(HistoryEvent $event): \DateTimeImmutable
    {
        $time = $event->getEventTime();
        $seconds = null === $time ? 0 : $time->getSeconds();
        $microseconds = null === $time ? 0 : intdiv($time->getNanos(), 1000);

        $moment = \DateTimeImmutable::createFromFormat(
            'U.u',
            \sprintf('%d.%06d', $seconds, $microseconds),
            new \DateTimeZone('UTC'),
        );

        // `createFromFormat` rend `false` sur une entrée qu'il ne sait pas lire. La seconde seule
        // reste une réponse juste, simplement moins précise.
        return false === $moment
            ? (new \DateTimeImmutable('@' . $seconds))->setTimezone(new \DateTimeZone('UTC'))
            : $moment->setTimezone(new \DateTimeZone('UTC'));
    }
}
