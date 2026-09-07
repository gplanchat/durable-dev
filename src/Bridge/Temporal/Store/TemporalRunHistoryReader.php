<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Store;

use Google\Protobuf\Duration;
use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Durable\Observation\ReadableDuration;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;

/**
 * Translates the Temporal history into readable events, in the vocabulary of the component.
 *
 * The order of the classification tests matters, and that is the trap the original code did not
 * avoid: `WORKFLOW_EXECUTION_SIGNALED` contains `WORKFLOW_`, and so does
 * `START_CHILD_WORKFLOW_EXECUTION_INITIATED`. Looking for `WORKFLOW_` first therefore files
 * signals and child workflows under the kind of the execution. The special cases come before the
 * general case.
 */
final class TemporalRunHistoryReader
{
    /**
     * The `Payloads` fields of the history, by their name in the message's JSON serialization.
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
     * The key of the action that the execution is for itself. Only one per history, and the
     * first one: its opening event carries number 1.
     */
    private const RUN_ACTION = 'workflow';

    /**
     * The pointers to the founding event of an action, in the order in which they carry authority.
     *
     * ⚠ **`getInitiatedEventId` comes before `getStartedEventId`**, and that is not cosmetic: the
     * end of a child execution carries both, and `startedEventId` designates the *start* of the
     * child, not the event that founded it. In the reverse order, every child workflow occupied
     * **two rows** of frieze — its request and its start on one, its end on the other — and
     * neither of the two told its duration. Measured on the probe covering every case: 9 actions
     * for 2 children where 7 were needed.
     *
     * @var list<string>
     */
    private const CORRELATION_GETTERS = [
        'getScheduledEventId',
        'getInitiatedEventId',
        'getStartedEventId',
    ];

    /**
     * What opens an action without designating anyone: a started timer, a scheduled activity.
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
                // A suffix is enough: Temporal names `_STARTED` every event by which a worker
                // takes over. `WORKFLOW_EXECUTION_STARTED` falls under it too, and that is
                // inert — it is event 1, nothing precedes it, so no interval can be counted as
                // a wait in front of it. The house journal keeps the same rule here,
                // `ExecutionStarted` included, so that the two backends do not diverge on a
                // case that changes nothing.
                str_ends_with($type, '_STARTED'),
                self::isFailure($type),
            );
        }

        return $history;
    }

    /**
     * The action the event is part of — the one it is the third act of, or the first.
     *
     * Temporal correlates by **event number**: everything that happens after a scheduling
     * designates it by `scheduledEventId`, `startedEventId` or `initiatedEventId` depending on
     * the family. The key is therefore the founding event, and the three accessors are tried in
     * that order — a completed workflow task carries the first two, and it is the scheduling
     * that opens the action.
     *
     * A founding event that designates nobody designates itself: without which a scheduled
     * activity would not belong to the action it opens.
     *
     * ⚠ `getParentInitiatedEventId` — carried by the start of a child execution — is
     * deliberately not in the list: it points to the history of the **parent**, not to an action
     * of that history. Confusing it would attach the start to a number from another journal.
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
     * What went wrong, and nothing else.
     *
     * Temporal suffixes `_FAILED` on every failure and `_TIMED_OUT` on every missed deadline, at
     * every level — activity, timer, child, external workflow, workflow task. Two suffixes
     * therefore cover the whole set with no table to maintain.
     *
     * ⚠ **`_CANCELED` and `_TERMINATED` are not among them**, and that is a decision: they are
     * outcomes, asked for by somebody. Painting them as breakdowns would send an operator looking
     * for an incident where there is only a cancellation — and red no longer means anything once
     * it covers both. ⚠ Temporal writes `CANCELED` with a single "l" where the house journal
     * writes `Cancelled`: a rule written on one side only misses the other in silence.
     */
    private static function isFailure(string $eventType): bool
    {
        return str_ends_with($eventType, '_FAILED') || str_ends_with($eventType, '_TIMED_OUT');
    }

    /**
     * The execution itself is **an action**: its start, its workflow tasks, its end.
     *
     * A workflow task is not a business fact — it is the mechanism by which the engine moves the
     * execution forward. Giving it a row per occurrence drowned the four interesting rows of an
     * order under four rows of plumbing bearing the same name.
     *
     * ⚠ **The exceptions are the essence of this rule**, and the same trap as for classification
     * into kinds: `WORKFLOW_EXECUTION_SIGNALED` and the `WORKFLOW_EXECUTION_UPDATE_*` family
     * start with the same prefix and are not the execution — a received signal and an update are
     * actions in their own right, with their own row. **Child** workflows
     * (`CHILD_WORKFLOW_EXECUTION_*`, `START_CHILD_WORKFLOW_EXECUTION_*`) and **external**
     * workflows (`EXTERNAL_`, `REQUEST_CANCEL_EXTERNAL_`, `SIGNAL_EXTERNAL_`) do not start with
     * that prefix: that is what leaves them their rows, and it is proven type by type.
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
     * The content of the event, as the server tells it.
     *
     * The attributes are a `oneof`: only one of the fifty accessors answers, and the name of the
     * chosen field is read from `getAttributes()`. The JSON serialization of the message then
     * gives everything the Temporal interface shows itself — activity type, queue, timeouts,
     * attempt, failure message — without our having to enumerate the fifty shapes.
     *
     * ⚠ **A payload would arrive there in base64**, because `Payload.data` is a `bytes` field. The
     * fields that carry one are read back over it with the bridge codec, the very one that wrote
     * them. An unreadable `input` in a diagnostic screen would be worse than no screen.
     *
     * A serialization failure returns an empty array: an administration screen that blows up on
     * an exotic event teaches nobody anything, whereas a row without detail is still a row.
     *
     * @return array<string, mixed>
     */
    private static function detailsOf(HistoryEvent $event): array
    {
        // `whichOneof` returns the name of the field that is set, or the empty string when none
        // is — which happens for an event type more recent than the generated stubs.
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
                // A payload that is not `json/plain` — another encoding, another producer. The
                // base64 form from the serialization stays in place: less readable, but present,
                // and that is still the better of the two possible answers.
            }
        }

        return $details;
    }

    private static function kindOf(string $eventType): WorkflowRunEventKind
    {
        return match (true) {
            // Before all the rest: NEXUS_OPERATION_CANCEL_REQUESTED contains CANCEL, and a rule
            // placed further down would let the variants through as server versions go by.
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
     * The business name first, the technical identifier next, the event type as a last resort:
     * `SendWelcomeEmail` is better than `act-1`, which is better than
     * `ACTIVITY TASK SCHEDULED`.
     */
    private static function labelOf(HistoryEvent $event, string $eventType): string
    {
        // A frieze row carries the name of its action. The events that open an execution — its
        // own, that of a child — name their workflow type, and that is the name the operator is
        // looking for, not "WORKFLOW EXECUTION STARTED" in capitals.
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

        // A timer carries no business name: "TIMER STARTED" names the event class, and the
        // operator looking at a frieze wants to know **how long** we are waiting, not that we
        // are waiting. The delay is the only fact the timer has, so that is its name.
        $timer = $event->getTimerStartedEventAttributes();
        if (null !== $timer) {
            return 'timer ' . ReadableDuration::of(self::secondsOf($timer->getStartToFireTimeout()));
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
     * The workflow type the event names, if it names one.
     *
     * `getWorkflowType()` is carried by the start of an execution just as by the events of a
     * child workflow — one single rule therefore names the row of the execution and those of its
     * children, with no correspondence table to maintain.
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

    /**
     * A protobuf duration in seconds. Both fields are cast explicitly: they are 64-bit integers
     * that the library sometimes returns as a string, and `strictBinaryOperands` refuses to mix
     * them with a float without saying so.
     */
    private static function secondsOf(?Duration $duration): float
    {
        if (null === $duration) {
            return 0.0;
        }

        return (float) $duration->getSeconds() + (float) $duration->getNanos() / 1_000_000_000.0;
    }

    private static function readableType(string $eventType): string
    {
        return str_replace('_', ' ', str_replace('EVENT_TYPE_', '', $eventType));
    }

    /**
     * ⚠ **Nanoseconds are not decorative.** Temporal timestamps to the nanosecond, and keeping
     * only the seconds of it crushed everything a fast execution did: sixteen events separated
     * by a few milliseconds read as the same instant. A frieze built on that piles all its
     * marks in the same place and no longer says anything. PHP stops at the microsecond, so that
     * is where the truncation happens — assumed, and six orders of magnitude lower.
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

        // `createFromFormat` returns `false` on an input it cannot read. The second alone is
        // still a correct answer, simply less precise.
        return false === $moment
            ? (new \DateTimeImmutable('@' . $seconds))->setTimezone(new \DateTimeZone('UTC'))
            : $moment->setTimezone(new \DateTimeZone('UTC'));
    }
}
