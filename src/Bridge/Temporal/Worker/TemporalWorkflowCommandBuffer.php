<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\ContinueAsNewOptions;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Failure\WorkflowFailureClassifier;
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
    /** @var list<Command> */
    private array $commands = [];

    public function __construct(
        private readonly TemporalConnection $connection,
        private readonly string $executionId,
        /**
         * Source des `scheduledEventId` réels pour {@see cancelActivity()}. Absente, l'annulation
         * ciblée d'activité n'est pas émise — voir la note de cette méthode.
         */
        private readonly ?TemporalExecutionHistory $history = null,
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
        $attrs->setMarkerName(TemporalExecutionHistory::MARKER_SIDE_EFFECT);

        // `details` est une map<string, Payloads> : un Payload seul y est refusé.
        $details = new \Google\Protobuf\Internal\MapField(
            \Google\Protobuf\Internal\GPBType::STRING,
            \Google\Protobuf\Internal\GPBType::MESSAGE,
            \Temporal\Api\Common\V1\Payloads::class,
        );
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
        // Le résultat est encodé tel quel : il était enveloppé dans ['result' => …] alors que ni
        // WorkflowClient::pollForCompletion() ni TemporalEventConverter ne déballent — l'appelant
        // recevait ['result' => x] au lieu de x, et le driver in-memory n'enveloppe pas non plus.
        $attrs = new CompleteWorkflowExecutionCommandAttributes();
        $attrs->setResult(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($result)));

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION);
        $cmd->setCompleteWorkflowExecutionCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    public function failWorkflow(\Throwable $reason): void
    {
        // Le pilote Temporal aplatissait tout échec sur un message brut : le `kind` de
        // WorkflowExecutionFailed (activité non gérée, échec catastrophique, handler…) était
        // perdu et l'événement domaine devenait irreconstituable à la relecture de l'historique.
        // Il voyage désormais dans les `details` de l'ApplicationFailureInfo ; `type` reste le
        // FQCN de l'exception, seul champ que le serveur confronte à nonRetryableErrorTypes.
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
     * `scheduledEventId` doit désigner l'événement ACTIVITY_TASK_SCHEDULED réel : il était
     * auparavant tiré d'un compteur local partant de 1000, donc sans rapport avec l'historique.
     * Le serveur rejette une tâche portant un identifiant inconnu, et l'identifiant n'existait
     * de toute façon que pour les activités planifiées dans la tâche courante — jamais celles
     * qu'on annule, planifiées lors d'une tâche antérieure.
     *
     * ponytail: une activité planifiée dans la tâche COURANTE n'a pas encore d'identifiant
     * d'événement ; sa commande n'est donc pas émise. Le cas n'est pas atteignable par l'API
     * (on n'annule qu'une opération déjà en attente), et le prédire demanderait de reproduire
     * l'attribution d'identifiants du serveur à partir de `startedEventId`.
     */
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
        // Sans objet côté Temporal : le serveur écrit lui-même CHILD_WORKFLOW_EXECUTION_COMPLETED
        // dans l'historique du parent quand l'enfant se termine.
    }

    public function failChildWorkflow(string $childExecutionId, \Throwable $reason): void
    {
        // Idem : CHILD_WORKFLOW_EXECUTION_FAILED est écrit par le serveur.
    }

    /**
     * COMMAND_TYPE_CONTINUE_AS_NEW_WORKFLOW_EXECUTION.
     *
     * Hors {@see WorkflowCommandBufferInterface} : le pilote in-memory journalise
     * {@see \Gplanchat\Durable\Event\WorkflowContinuedAsNew} directement depuis
     * {@see \Gplanchat\Durable\ExecutionEngine}, sans passer par le buffer de commandes.
     *
     * @param array<string, mixed> $payload
     */
    public function continueAsNew(string $workflowType, array $payload, ?ContinueAsNewOptions $options = null): void
    {
        $attrs = new \Temporal\Api\Command\V1\ContinueAsNewWorkflowExecutionCommandAttributes();
        $attrs->setWorkflowType(new \Temporal\Api\Common\V1\WorkflowType(['name' => $workflowType]));
        $attrs->setInput(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($payload)));

        $metadata = null !== $options ? $options->toMetadata() : [];
        $taskQueue = $metadata['task_queue'] ?? null;
        $attrs->setTaskQueue(new TaskQueue([
            'name' => \is_string($taskQueue) && '' !== $taskQueue ? $taskQueue : $this->connection->workflowTaskQueue,
        ]));
        foreach ([
            'workflow_run_timeout_seconds' => 'setWorkflowRunTimeout',
            'workflow_task_timeout_seconds' => 'setWorkflowTaskTimeout',
        ] as $key => $setter) {
            $seconds = $metadata[$key] ?? null;
            if (is_numeric($seconds) && (float) $seconds > 0) {
                $attrs->{$setter}($this->durationSeconds((float) $seconds));
            }
        }

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_CONTINUE_AS_NEW_WORKFLOW_EXECUTION);
        $cmd->setContinueAsNewWorkflowExecutionCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    /**
     * COMMAND_TYPE_CANCEL_WORKFLOW_EXECUTION — seule réponse qui clôt réellement une exécution
     * dont l'annulation a été demandée. Sans elle le serveur replanifie une tâche de workflow
     * et l'exécution continue de tourner.
     *
     * Hors {@see WorkflowCommandBufferInterface} : côté in-memory, l'annulation est journalisée
     * par {@see \Gplanchat\Durable\Store\EventStoreWorkflowLifecycle}.
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
     * Marqueur d'annulation livrée : l'historique Temporal ne peut pas porter la *raison* d'une
     * annulation d'opération, si bien qu'au rejeu un ACTIVITY_TASK_CANCELED se relit en
     * ActivitySupersededException — le `catch (WorkflowCancelledFailure)` du workflow ne
     * matcherait plus et la compensation divergerait d'une tâche à l'autre.
     *
     * @param list<string> $targetIds
     */
    public function recordCancellationDelivered(array $targetIds): void
    {
        $attrs = new \Temporal\Api\Command\V1\RecordMarkerCommandAttributes();
        $attrs->setMarkerName(TemporalExecutionHistory::MARKER_CANCELLATION_DELIVERED);

        $details = new \Google\Protobuf\Internal\MapField(
            \Google\Protobuf\Internal\GPBType::STRING,
            \Google\Protobuf\Internal\GPBType::MESSAGE,
            \Temporal\Api\Common\V1\Payloads::class,
        );
        $details['targets'] = JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($targetIds));
        $attrs->setDetails($details);

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_RECORD_MARKER);
        $cmd->setRecordMarkerCommandAttributes($attrs);
        $this->commands[] = $cmd;
    }

    public function cancelTimer(string $timerId, string $reason): void
    {
        $attrs = new \Temporal\Api\Command\V1\CancelTimerCommandAttributes();
        $attrs->setTimerId($timerId);

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_CANCEL_TIMER);
        $cmd->setCancelTimerCommandAttributes($attrs);
        $this->commands[] = $cmd;
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
