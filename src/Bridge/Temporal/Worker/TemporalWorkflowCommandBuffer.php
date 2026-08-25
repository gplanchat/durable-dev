<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Google\Protobuf\Duration;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Command\V1\CompleteWorkflowExecutionCommandAttributes;
use Temporal\Api\Command\V1\FailWorkflowExecutionCommandAttributes;
use Temporal\Api\Command\V1\RequestCancelActivityTaskCommandAttributes;
use Temporal\Api\Command\V1\ScheduleActivityTaskCommandAttributes;
use Temporal\Api\Command\V1\StartTimerCommandAttributes;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Common\V1\RetryPolicy;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\ParentClosePolicy as TemporalParentClosePolicy;
use Temporal\Api\Enums\V1\WorkflowIdReusePolicy as TemporalIdReusePolicy;
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
    /** @var list<Command> */
    private array $commands = [];

    /** @var array<string, int> activity ID → scheduled event ID (for cancel) */
    private array $activityIdToEventId = [];

    private int $nextEventId = 1000;

    public function __construct(
        private readonly TemporalConnection $connection,
        private readonly string $executionId,
    ) {
    }

    public function scheduleActivity(string $activityId, string $activityName, array $payload, array $metadata): void
    {
        $options = ActivityOptions::fromMetadata($metadata);

        $taskQueueName = null !== $options && null !== $options->taskQueue && '' !== $options->taskQueue
            ? $options->taskQueue
            : $this->connection->activityTaskQueue;

        $attrs = new ScheduleActivityTaskCommandAttributes();
        $attrs->setActivityId($activityId);
        $attrs->setActivityType(new ActivityType(['name' => $activityName]));
        $attrs->setTaskQueue(new TaskQueue(['name' => $taskQueueName]));

        $scheduled = new ActivityScheduled($this->executionId, $activityId, $activityName, $payload, $metadata);
        $attrs->setInput(TemporalActivityScheduleInput::toPayloads($scheduled));

        $stc = null !== $options && null !== $options->startToCloseTimeoutSeconds && $options->startToCloseTimeoutSeconds > 0
            ? $options->startToCloseTimeoutSeconds
            : 30.0;
        $attrs->setStartToCloseTimeout($this->durationSeconds($stc));

        if (null !== $options) {
            if (null !== $options->scheduleToCloseTimeoutSeconds && $options->scheduleToCloseTimeoutSeconds > 0) {
                $attrs->setScheduleToCloseTimeout($this->durationSeconds($options->scheduleToCloseTimeoutSeconds));
            }
            if (null !== $options->scheduleToStartTimeoutSeconds && $options->scheduleToStartTimeoutSeconds > 0) {
                $attrs->setScheduleToStartTimeout($this->durationSeconds($options->scheduleToStartTimeoutSeconds));
            }
            if (null !== $options->heartbeatTimeoutSeconds && $options->heartbeatTimeoutSeconds > 0) {
                $attrs->setHeartbeatTimeout($this->durationSeconds($options->heartbeatTimeoutSeconds));
            }

            // Retry is governed by the Temporal server via this policy. Without it the
            // server applies its default (unbounded retries), so a bounded maxAttempts
            // or a non-retryable business exception only takes effect once it is set here.
            // The server treats a failure as non-retryable when its ApplicationFailureInfo
            // type matches nonRetryableErrorTypes (the exception FQCNs).
            $retryPolicy = new RetryPolicy();
            $retryPolicy->setInitialInterval($this->durationSeconds($options->initialIntervalSeconds));
            $retryPolicy->setBackoffCoefficient($options->backoffCoefficient);
            if (null !== $options->maximumIntervalSeconds && $options->maximumIntervalSeconds > 0) {
                $retryPolicy->setMaximumInterval($this->durationSeconds($options->maximumIntervalSeconds));
            }
            if ($options->maxAttempts > 0) {
                $retryPolicy->setMaximumAttempts($options->maxAttempts);
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

        $this->activityIdToEventId[$activityId] = $this->nextEventId++;
        $this->commands[] = $cmd;
    }

    public function startTimer(string $timerId, float $scheduledAt, string $summary): void
    {
        $attrs = new StartTimerCommandAttributes();
        $attrs->setTimerId($timerId);
        // `$scheduledAt` est une échéance absolue ; Temporal attend une durée. Sans
        // `start_to_fire_timeout` le serveur rejette la commande — le minuteur ne partait jamais.
        // ponytail: plancher à 1 ms si la tâche a été traitée après l'échéance (latence de poll) ;
        // un vrai rattrapage demanderait l'horloge serveur, pas microtime().
        $attrs->setStartToFireTimeout($this->durationSeconds(max(0.001, $scheduledAt - microtime(true))));

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_START_TIMER);
        $cmd->setStartTimerCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    public function recordSideEffect(string $sideEffectId, mixed $result): void
    {
        $attrs = new \Temporal\Api\Command\V1\RecordMarkerCommandAttributes();
        $attrs->setMarkerName('SideEffect');

        $details = new \Google\Protobuf\Internal\MapField(
            \Google\Protobuf\Internal\GPBType::STRING,
            \Google\Protobuf\Internal\GPBType::MESSAGE,
            \Temporal\Api\Common\V1\Payload::class,
        );
        $details['result'] = JsonPlainPayload::encode($result);
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
        array $schedulingMetadata,
    ): void {
        $attrs = new \Temporal\Api\Command\V1\StartChildWorkflowExecutionCommandAttributes();
        $attrs->setWorkflowId($childExecutionId);
        $attrs->setWorkflowType(new \Temporal\Api\Common\V1\WorkflowType(['name' => $childWorkflowType]));

        $taskQueue = $schedulingMetadata['task_queue'] ?? null;
        $attrs->setTaskQueue(new TaskQueue([
            'name' => \is_string($taskQueue) && '' !== $taskQueue ? $taskQueue : $this->connection->workflowTaskQueue,
        ]));
        $attrs->setInput(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($input)));

        $namespace = $schedulingMetadata['namespace'] ?? null;
        if (\is_string($namespace) && '' !== $namespace) {
            $attrs->setNamespace($namespace);
        }
        $cron = $schedulingMetadata['cron_schedule'] ?? null;
        if (\is_string($cron) && '' !== $cron) {
            $attrs->setCronSchedule($cron);
        }
        foreach ([
            'workflow_execution_timeout_seconds' => 'setWorkflowExecutionTimeout',
            'workflow_run_timeout_seconds' => 'setWorkflowRunTimeout',
            'workflow_task_timeout_seconds' => 'setWorkflowTaskTimeout',
        ] as $key => $setter) {
            $seconds = $schedulingMetadata[$key] ?? null;
            if (is_numeric($seconds) && (float) $seconds > 0) {
                $attrs->{$setter}($this->durationSeconds((float) $seconds));
            }
        }

        // Sans ces deux politiques le serveur applique ses défauts : la ParentClosePolicy
        // choisie par l'appelant était silencieusement perdue côté Temporal.
        $attrs->setParentClosePolicy(self::toTemporalParentClosePolicy($schedulingMetadata['parentClosePolicy'] ?? null));
        $attrs->setWorkflowIdReusePolicy(self::toTemporalIdReusePolicy($schedulingMetadata['workflow_id_reuse_policy'] ?? null));

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_START_CHILD_WORKFLOW_EXECUTION);
        $cmd->setStartChildWorkflowExecutionCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    public function completeWorkflow(mixed $result): void
    {
        $attrs = new CompleteWorkflowExecutionCommandAttributes();
        $attrs->setResult(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode(['result' => $result])));

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION);
        $cmd->setCompleteWorkflowExecutionCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    public function failWorkflow(\Throwable $reason): void
    {
        $failure = new Failure();
        $failure->setMessage($reason->getMessage());
        $failure->setSource('DurableWorkflowWorker');

        $attrs = new FailWorkflowExecutionCommandAttributes();
        $attrs->setFailure($failure);

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION);
        $cmd->setFailWorkflowExecutionCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    public function cancelActivity(string $activityId, string $reason): void
    {
        $scheduledEventId = $this->activityIdToEventId[$activityId] ?? null;
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

    private static function toTemporalParentClosePolicy(mixed $policy): int
    {
        $value = $policy instanceof ParentClosePolicy ? $policy : ParentClosePolicy::tryFrom((string) (\is_scalar($policy) ? $policy : ''));

        return match ($value) {
            ParentClosePolicy::Abandon => TemporalParentClosePolicy::PARENT_CLOSE_POLICY_ABANDON,
            ParentClosePolicy::RequestCancel => TemporalParentClosePolicy::PARENT_CLOSE_POLICY_REQUEST_CANCEL,
            default => TemporalParentClosePolicy::PARENT_CLOSE_POLICY_TERMINATE,
        };
    }

    private static function toTemporalIdReusePolicy(mixed $policy): int
    {
        $value = $policy instanceof WorkflowIdReusePolicy ? $policy : WorkflowIdReusePolicy::tryFrom((string) (\is_scalar($policy) ? $policy : ''));

        return match ($value) {
            WorkflowIdReusePolicy::AllowDuplicate => TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_ALLOW_DUPLICATE,
            WorkflowIdReusePolicy::RejectDuplicate => TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_REJECT_DUPLICATE,
            default => TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_ALLOW_DUPLICATE_FAILED_ONLY,
        };
    }

    private function durationSeconds(float $seconds): Duration
    {
        $d = new Duration();
        $whole = (int) floor($seconds);
        $nanos = (int) round(($seconds - $whole) * 1_000_000_000);
        if ($nanos >= 1_000_000_000) {
            ++$whole;
            $nanos -= 1_000_000_000;
        }
        $d->setSeconds($whole);
        $d->setNanos($nanos);

        return $d;
    }
}
