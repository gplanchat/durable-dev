<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Google\Protobuf\Duration;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\ContinueAsNewOptions;
use Gplanchat\Durable\Duration as DurableDuration;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Failure\WorkflowFailureClassifier;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Versioning\ChangePoint;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Command\V1\CompleteWorkflowExecutionCommandAttributes;
use Temporal\Api\Command\V1\FailWorkflowExecutionCommandAttributes;
use Temporal\Api\Command\V1\RequestCancelActivityTaskCommandAttributes;
use Temporal\Api\Command\V1\RequestCancelNexusOperationCommandAttributes;
use Temporal\Api\Command\V1\ScheduleActivityTaskCommandAttributes;
use Temporal\Api\Command\V1\ScheduleNexusOperationCommandAttributes;
use Temporal\Api\Command\V1\StartTimerCommandAttributes;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Common\V1\RetryPolicy;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Failure\V1\ApplicationFailureInfo;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\Taskqueue\V1\TaskQueue;

/**
 * Implements WorkflowCommandBufferInterface by building Temporal protobuf Command objects.
 *
 * Commands are collected and flushed into RespondWorkflowTaskCompleted::commands.
 * Used by the Temporal backend (WorkflowTaskRunner → WorkflowTaskProcessor).
 */
final class TemporalWorkflowCommandBuffer implements WorkflowCommandBufferInterface
{
    /** Execution bound set when the activity fixes none; the server demands one. */
    private const DEFAULT_EXECUTION_BOUND_SECONDS = 30.0;

    /** @var list<Command> */
    private array $commands = [];

    public function __construct(
        private readonly TemporalConnection $connection,
        private readonly string $executionId,
        /**
         * Source of the real `scheduledEventId`s for {@see cancelActivity()}. Absent, targeted
         * activity cancellation is not emitted — see that method's note.
         */
        private readonly ?TemporalExecutionHistory $history = null,
    ) {}

    public function scheduleActivity(string $activityId, string $activityName, array $payload, ?ActivityOptions $options): void
    {
        $taskQueueName = ((null !== $options ? $options->taskQueue : null) ?? $this->connection->activityTaskQueue)->name();

        $attrs = new ScheduleActivityTaskCommandAttributes();
        $attrs->setActivityId($activityId);
        $attrs->setActivityType(new ActivityType(['name' => $activityName]));
        $attrs->setTaskQueue(new TaskQueue(['name' => $taskQueueName]));

        // The worker will read these options back from the activity input: this is the wire, it
        // keeps its flat shape. The server timestamps the queueing itself.
        $scheduled = new ActivityScheduled(
            $this->executionId,
            $activityId,
            $activityName,
            $payload,
            $options?->toMetadata() ?? [],
        );
        $attrs->setInput(TemporalActivityScheduleInput::toPayloads($scheduled));

        // The server refuses an activity with no closing bound: the fallback is named on the
        // domain side rather than hidden inside a `?: 30.0`.
        $timeouts = null !== $options ? $options->timeouts : ActivityTimeouts::none();
        $attrs->setStartToCloseTimeout($this->durationSeconds(
            $timeouts->executionBoundOr(DurableDuration::seconds(self::DEFAULT_EXECUTION_BOUND_SECONDS))->toSeconds(),
        ));

        if (null !== $options) {
            if (null !== $timeouts->scheduleToClose) {
                $attrs->setScheduleToCloseTimeout($this->durationSeconds($timeouts->scheduleToClose->toSeconds()));
            }
            if (null !== $timeouts->scheduleToStart) {
                $attrs->setScheduleToStartTimeout($this->durationSeconds($timeouts->scheduleToStart->toSeconds()));
            }
            if (null !== $timeouts->heartbeat) {
                $attrs->setHeartbeatTimeout($this->durationSeconds($timeouts->heartbeat->toSeconds()));
            }

            // Retry is governed by the Temporal server via this policy. Without it the
            // server applies its default (unbounded retries), so a bounded RetryLimit
            // or a non-retryable business exception only takes effect once it is set here.
            // The server treats a failure as non-retryable when its ApplicationFailureInfo
            // type matches nonRetryableErrorTypes (the exception FQCNs).
            $retryPolicy = new RetryPolicy();
            $retryPolicy->setInitialInterval($this->durationSeconds($options->initialInterval->toSeconds()));
            $retryPolicy->setBackoffCoefficient($options->backoffCoefficient);
            if (null !== $options->maximumInterval) {
                $retryPolicy->setMaximumInterval($this->durationSeconds($options->maximumInterval->toSeconds()));
            }
            if (!$options->retryLimit->isUnlimited()) {
                $retryPolicy->setMaximumAttempts($options->retryLimit->maxAttempts());
            }
            if ([] !== $options->nonRetryableExceptions) {
                // Already a list<class-string> (per ActivityOptions) — pass as-is.
                $retryPolicy->setNonRetryableErrorTypes($options->nonRetryableExceptions);
            }
            $attrs->setRetryPolicy($retryPolicy);
        }

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK);
        $cmd->setScheduleActivityTaskCommandAttributes($attrs);

        $this->commands[] = $cmd;
    }

    public function startTimer(string $timerId, DurableDuration $delay, string $summary): void
    {
        $attrs = new StartTimerCommandAttributes();
        $attrs->setTimerId($timerId);
        // The server wants a duration, and it gets one: no more deadline subtraction, no more
        // floor to catch up with poll latency. The flaw was carried by the port.
        $attrs->setStartToFireTimeout($this->durationSeconds($delay->toSeconds()));

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_START_TIMER);
        $cmd->setStartTimerCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    public function recordSideEffect(string $sideEffectId, mixed $result): void
    {
        $attrs = new \Temporal\Api\Command\V1\RecordMarkerCommandAttributes();
        $attrs->setMarkerName(TemporalExecutionHistory::MARKER_SIDE_EFFECT);

        // `details` is a map<string, Payloads>: a lone Payload is refused there.
        $details = self::protobufMap(\Temporal\Api\Common\V1\Payloads::class);
        $details['result'] = JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($result));
        $attrs->setDetails($details);

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_RECORD_MARKER);
        $cmd->setRecordMarkerCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    public function scheduleChildWorkflow(
        string $childExecutionId,
        string $childWorkflowType,
        array $input,
        ChildWorkflowOptions $options,
    ): void {
        $attrs = new \Temporal\Api\Command\V1\StartChildWorkflowExecutionCommandAttributes();
        $attrs->setWorkflowId($childExecutionId);
        $attrs->setWorkflowType(new \Temporal\Api\Common\V1\WorkflowType(['name' => $childWorkflowType]));
        $attrs->setTaskQueue(new TaskQueue([
            'name' => ($options->taskQueue ?? $this->connection->workflowTaskQueue)->name(),
        ]));
        $attrs->setInput(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($input)));

        if (null !== $options->namespace) {
            $attrs->setNamespace($options->namespace->name());
        }
        if (null !== $options->cronSchedule) {
            $attrs->setCronSchedule($options->cronSchedule->toExpression());
        }
        TemporalPolicyMapper::applyWorkflowTimeouts($options->timeouts, $attrs);
        TemporalPolicyMapper::applySearchAttributes($options->searchAttributes, $attrs);

        // Without these two policies the server applies its defaults: the ParentClosePolicy
        // chosen by the caller was silently lost on the Temporal side.
        $attrs->setParentClosePolicy(TemporalPolicyMapper::parentClosePolicy($options->parentClosePolicy));
        $attrs->setWorkflowIdReusePolicy(TemporalPolicyMapper::idReusePolicy($options->workflowIdReusePolicy));

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_START_CHILD_WORKFLOW_EXECUTION);
        $cmd->setStartChildWorkflowExecutionCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    public function completeWorkflow(mixed $result): void
    {
        // The result is encoded as-is: it used to be wrapped in ['result' => …] while neither
        // WorkflowClient::pollForCompletion() nor TemporalEventConverter unwrap — the caller got
        // ['result' => x] instead of x, and the in-memory driver does not wrap either.
        $attrs = new CompleteWorkflowExecutionCommandAttributes();
        $attrs->setResult(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($result)));

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION);
        $cmd->setCompleteWorkflowExecutionCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    /**
     * The version marker, in the exact shape the server records — taken from the history of a
     * versioned Go SDK workflow, then re-emitted from here and accepted (tasks 1.1–1.2). A
     * versioned Durable execution therefore reads in the Temporal UI like a Go one.
     *
     * The `TemporalChangeVersion` upsert accompanies the marker and is not decorative: it is what
     * makes "which live executions are still on version N" answerable, hence what says when an old
     * branch can disappear. Writing the marker without it would work, and would cost that answer
     * in silence.
     */
    public function recordVersion(string $changeId, int $version): void
    {
        $details = self::protobufMap(\Temporal\Api\Common\V1\Payloads::class);
        $details[ChangePoint::DETAIL_CHANGE_ID] = JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($changeId));
        $details[ChangePoint::DETAIL_VERSION] = JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($version));

        $marker = new \Temporal\Api\Command\V1\RecordMarkerCommandAttributes();
        $marker->setMarkerName(ChangePoint::MARKER_NAME);
        $marker->setDetails($details);

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_RECORD_MARKER);
        $cmd->setRecordMarkerCommandAttributes($marker);
        $this->commands[] = $cmd;

        $fields = self::protobufMap(\Temporal\Api\Common\V1\Payload::class);
        // The Go SDK writes a KeywordList: the type travels in the payload metadata.
        $value = JsonPlainPayload::encode([ChangePoint::searchAttributeValue($changeId, $version)]);
        $value->setMetadata(['encoding' => 'json/plain', 'type' => 'KeywordList']);
        $fields[ChangePoint::SEARCH_ATTRIBUTE] = $value;

        $attrs = new \Temporal\Api\Command\V1\UpsertWorkflowSearchAttributesCommandAttributes();
        $attrs->setSearchAttributes(new \Temporal\Api\Common\V1\SearchAttributes(['indexed_fields' => $fields]));

        $upsert = new Command();
        $upsert->setCommandType(CommandType::COMMAND_TYPE_UPSERT_WORKFLOW_SEARCH_ATTRIBUTES);
        $upsert->setUpsertWorkflowSearchAttributesCommandAttributes($attrs);
        $this->commands[] = $upsert;
    }

    /**
     * The `map<string, T>` map that the protobuf attributes expect.
     *
     * Four commands in this file build one, identically. A single factory because Psalm gets the
     * `GPBType` constants wrong — they are integers, it expects a `long` — and because a single
     * place to silence is better than four.
     *
     * @param class-string $valueClass
     *
     * @psalm-suppress InvalidArgument
     */
    private static function protobufMap(string $valueClass): \Google\Protobuf\Internal\MapField
    {
        return new \Google\Protobuf\Internal\MapField(
            \Google\Protobuf\Internal\GPBType::STRING,
            \Google\Protobuf\Internal\GPBType::MESSAGE,
            $valueClass,
        );
    }

    public function failWorkflow(\Throwable $reason): void
    {
        // The Temporal driver used to flatten every failure onto a raw message: the `kind` of
        // WorkflowExecutionFailed (unhandled activity, catastrophic failure, handler…) was lost
        // and the domain event became unreconstructable when reading the history back. It now
        // travels in the ApplicationFailureInfo `details`; `type` stays the exception FQCN, the
        // only field the server matches against nonRetryableErrorTypes.
        $classified = WorkflowFailureClassifier::classify($this->executionId, $reason);

        $info = new ApplicationFailureInfo();
        $info->setType($classified->failureClass());
        $info->setDetails(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($classified->payload())));

        $failure = new Failure();
        $failure->setMessage($classified->failureMessage());
        $failure->setSource('DurableWorkflowWorker');
        $failure->setApplicationFailureInfo($info);

        $attrs = new FailWorkflowExecutionCommandAttributes();
        $attrs->setFailure($failure);

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION);
        $cmd->setFailWorkflowExecutionCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    /**
     * COMMAND_TYPE_REQUEST_CANCEL_ACTIVITY_TASK.
     *
     * `scheduledEventId` must designate the real ACTIVITY_TASK_SCHEDULED event: it used to be
     * drawn from a local counter starting at 1000, hence unrelated to the history. The server
     * rejects a task carrying an unknown id, and the id only existed anyway for the activities
     * scheduled in the current task — never those being cancelled, scheduled during an earlier
     * task.
     *
     * ponytail: an activity scheduled in the CURRENT task does not have an event id yet; its
     * command is therefore not emitted. The case is not reachable through the API (only an
     * already pending operation is cancelled), and predicting it would mean reproducing the
     * server's id assignment from `startedEventId`.
     */
    public function recordUpdateHandled(string $updateName, array $arguments, mixed $result, ?FailureEnvelope $failure): void
    {
        // Deliberately empty: it is the **server** that writes UPDATE_ACCEPTED and
        // UPDATE_COMPLETED, from the protocol messages the worker hands back to it
        // ({@see UpdateProtocol}). A worker that journalled as well would duplicate the work.
    }

    public function cancelActivity(string $activityId, string $reason): void
    {
        $scheduledEventId = $this->history?->scheduledEventIdForActivity($activityId);
        if (null === $scheduledEventId) {
            return;
        }

        $attrs = new RequestCancelActivityTaskCommandAttributes();
        $attrs->setScheduledEventId($scheduledEventId);

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_REQUEST_CANCEL_ACTIVITY_TASK);
        $cmd->setRequestCancelActivityTaskCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    /**
     * Returns and clears the buffered commands.
     *
     * @return list<Command>
     */
    public function flush(): array
    {
        $cmds = $this->commands;
        $this->commands = [];

        return $cmds;
    }

    /**
     * Returns buffered commands without clearing.
     *
     * @return list<Command>
     */
    public function peek(): array
    {
        return $this->commands;
    }

    public function completeChildWorkflow(string $childExecutionId, mixed $result): void
    {
        // Moot on the Temporal side: the server itself writes CHILD_WORKFLOW_EXECUTION_COMPLETED
        // into the parent's history when the child ends.
    }

    public function failChildWorkflow(string $childExecutionId, \Throwable $reason): void
    {
        // Same thing: CHILD_WORKFLOW_EXECUTION_FAILED is written by the server.
    }

    /**
     * COMMAND_TYPE_CONTINUE_AS_NEW_WORKFLOW_EXECUTION.
     *
     * Outside {@see WorkflowCommandBufferInterface}: the in-memory driver journals
     * {@see \Gplanchat\Durable\Event\WorkflowContinuedAsNew} directly from
     * {@see \Gplanchat\Durable\ExecutionEngine}, without going through the command buffer.
     *
     * @param array<string, mixed> $payload
     */
    public function continueAsNew(string $workflowType, array $payload, ?ContinueAsNewOptions $options = null): void
    {
        $attrs = new \Temporal\Api\Command\V1\ContinueAsNewWorkflowExecutionCommandAttributes();
        $attrs->setWorkflowType(new \Temporal\Api\Common\V1\WorkflowType(['name' => $workflowType]));
        $attrs->setInput(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($payload)));

        $options ??= ContinueAsNewOptions::new();
        $attrs->setTaskQueue(new TaskQueue([
            'name' => ($options->taskQueue ?? $this->connection->workflowTaskQueue)->name(),
        ]));
        TemporalPolicyMapper::applyWorkflowTimeouts($options->timeouts, $attrs);

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_CONTINUE_AS_NEW_WORKFLOW_EXECUTION);
        $cmd->setContinueAsNewWorkflowExecutionCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    /**
     * COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION — the only answer that actually closes an execution
     * whose cancellation has been requested. Without it the server reschedules a workflow task
     * and the execution keeps running.
     *
     * Outside {@see WorkflowCommandBufferInterface}: on the in-memory side, the cancellation is
     * journalled by {@see \Gplanchat\Durable\Store\EventStoreWorkflowLifecycle}.
     */
    public function cancelWorkflow(string $reason): void
    {
        $attrs = new \Temporal\Api\Command\V1\CancelWorkflowExecutionCommandAttributes();
        $attrs->setDetails(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode(['reason' => $reason])));

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION);
        $cmd->setCancelWorkflowExecutionCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    /**
     * Delivered-cancellation marker: Temporal history cannot carry the *reason* of an operation
     * cancellation, so that on replay an ACTIVITY_TASK_CANCELED reads back as an
     * ActivitySupersededException — the workflow's `catch (WorkflowCancelledFailure)` would no
     * longer match and the compensation would diverge from one task to the next.
     *
     * @param list<string> $targetIds
     */
    public function recordCancellationDelivered(array $targetIds): void
    {
        $attrs = new \Temporal\Api\Command\V1\RecordMarkerCommandAttributes();
        $attrs->setMarkerName(TemporalExecutionHistory::MARKER_CANCELLATION_DELIVERED);

        /** @psalm-suppress InvalidArgument — the google/protobuf stubs type the GPBType constants as `long` */
        $details = self::protobufMap(\Temporal\Api\Common\V1\Payloads::class);
        $details['targets'] = JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($targetIds));
        $attrs->setDetails($details);

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_RECORD_MARKER);
        $cmd->setRecordMarkerCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    /**
     * Replay goes back through the losers' cancellation on every resume, and a cancelled timer
     * has no verdict: it comes back to waiting, and the cancellation is asked for again.
     *
     * On the SQL journal {@see \Gplanchat\Durable\Store\EventStoreCommandBuffer::cancelTimer()}
     * has guarded against it for a long time — at worst a duplicate event. Here Temporal rejects
     * the whole task (`BadCancelTimerAttributes: invalid history builder state for action:
     * add-timer-canceled-event`), the worker dies, and the redelivered task kills it again: a
     * single execution poisons the whole queue.
     */
    public function cancelTimer(string $timerId, string $reason): void
    {
        if (true === $this->history?->isTimerSettled($timerId)) {
            return;
        }

        $attrs = new \Temporal\Api\Command\V1\CancelTimerCommandAttributes();
        $attrs->setTimerId($timerId);

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_CANCEL_TIMER);
        $cmd->setCancelTimerCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    private function durationSeconds(float $seconds): Duration
    {
        return TemporalPolicyMapper::duration($seconds);
    }

    /**
     * Emits `ScheduleNexusOperation`.
     *
     * The three bounds are only set if the domain carries one. Probed (§1.3), the server applies
     * no default and records only what it is given: setting one "to fill it in" would invent a
     * constraint the caller did not ask for. An infinite envelope leaves as `0`, which is how
     * Temporal writes "no bound" — and which, measured, does not shave the sub-bounds.
     *
     * No Nexus header: nothing on the domain side carries one yet, and an empty field is not a
     * header. The day a caller needs one, it is the port that will have to transport it.
     */
    public function scheduleNexusOperation(
        string $operationId,
        NexusEndpoint $endpoint,
        NexusService $service,
        NexusOperationName $operation,
        array $payload,
        NexusOperationTimeouts $timeouts,
        NexusOperationHeaders $headers,
    ): void {
        $attrs = new ScheduleNexusOperationCommandAttributes();
        $attrs->setEndpoint($endpoint->name());
        $attrs->setService($service->name());
        $attrs->setOperation($operation->name());
        // A Nexus operation carries ONE payload, where an activity carries a list of them: the
        // field is a Payload, not a Payloads.
        // The caller's payload, bare. Until now it carried a `{operationId, payload}` envelope
        // that served to correlate — and that a handler from another SDK received in place of
        // the fields it expects. Measured (task 1.1): a Go handler received `{"name":""}` and
        // answered on emptiness, without anything raising.
        //
        // The correlation is already on the wire: the server assigns a `scheduledEventId` that
        // both the scheduling event and the terminal events carry.
        $attrs->setInput(JsonPlainPayload::encode($payload));

        if (null !== $timeouts->scheduleToClose) {
            $attrs->setScheduleToCloseTimeout($this->nexusBound($timeouts->scheduleToClose));
        }
        if (null !== $timeouts->scheduleToStart) {
            $attrs->setScheduleToStartTimeout($this->nexusBound($timeouts->scheduleToStart));
        }
        if (null !== $timeouts->startToClose) {
            $attrs->setStartToCloseTimeout($this->nexusBound($timeouts->startToClose));
        }

        // An empty map is not an absent map to whoever reads a history back: the field is only
        // written if there is something to carry.
        if (!$headers->isEmpty()) {
            // `setNexusHeader()` accepts an array as well as a MapField, and the array avoids
            // handling a type whose static stubs do not say the key.
            $attrs->setNexusHeader($headers->toArray());
        }

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_SCHEDULE_NEXUS_OPERATION);
        $cmd->setScheduleNexusOperationCommandAttributes($attrs);

        $this->commands[] = $cmd;
    }

    /**
     * A Nexus operation bound on the wire: the domain's infinity is written `0` there.
     */
    private function nexusBound(DurableDuration $bound): Duration
    {
        return $this->durationSeconds($bound->isInfinite() ? 0.0 : $bound->toSeconds());
    }

    public function cancelNexusOperation(string $operationId, string $reason): void
    {
        // Same rule as for an activity: the server wants the real eventId of the scheduling, and
        // rejects the whole task if the id matches nothing. An operation that cannot be found
        // again in the history has nothing to cancel — better to stay quiet than to invent.
        $scheduledEventId = $this->history?->scheduledEventIdForNexusOperation($operationId);
        if (null === $scheduledEventId) {
            return;
        }

        $attrs = new RequestCancelNexusOperationCommandAttributes();
        $attrs->setScheduledEventId($scheduledEventId);

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_REQUEST_CANCEL_NEXUS_OPERATION);
        $cmd->setRequestCancelNexusOperationCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }
}
