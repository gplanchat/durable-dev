<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Temporal\Api\Failure\V1\ApplicationFailureInfo;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCanceledRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedRequest;

/**
 * Poll la file d’activités Temporal, exécute le chemin {@see ActivityMessageProcessor} (journal + resume)
 * et répond au serveur ({@code RespondActivityTaskCompleted} / {@code RespondActivityTaskFailed}).
 *
 * À utiliser avec des tâches planifiées par {@see \Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor}
 * et une entrée {@see \Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput}.
 */
final class TemporalActivityWorker
{
    public function __construct(
        private readonly WorkflowServiceActivityRpc $activityRpc,
        private readonly TemporalConnection $connection,
        private readonly ActivityMessageProcessor $processor,
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityHeartbeatSenderInterface $heartbeatSender,
    ) {
    }

    /**
     * Un long-poll ; si une tâche est reçue, traitement + réponse gRPC.
     */
    public function pollOnce(): void
    {
        $req = new PollActivityTaskQueueRequest();
        $req->setNamespace($this->connection->namespace);
        $req->setTaskQueue(new TaskQueue(['name' => $this->connection->activityTaskQueue]));
        $req->setIdentity($this->connection->identity.'-activity');

        $resp = $this->activityRpc->pollActivityTaskQueue($req);

        if ('' === $resp->getTaskToken()) {
            return;
        }

        $message = TemporalActivityScheduleInput::toActivityMessage($resp);

        if (ActivityEventJournal::hasTerminalOutcomeForActivity(
            $this->eventStore,
            $message->executionId,
            $message->activityId,
        )) {
            $terminal = $this->lastTerminalEvent($message->executionId, $message->activityId);
            if ($terminal instanceof ActivityCompleted) {
                $this->respondCompleted($resp, $terminal->result());

                return;
            }
            if ($terminal instanceof ActivityFailed) {
                $this->respondFailed($resp, $terminal);

                return;
            }
            if ($terminal instanceof ActivityCancelled) {
                $this->respondCanceled($resp);

                return;
            }
        }

        $options = ActivityOptions::fromMetadata($message->metadata);
        if (null !== $options && null !== $options->heartbeatTimeoutSeconds && $options->heartbeatTimeoutSeconds > 0) {
            if ($this->heartbeatSender instanceof TemporalActivityHeartbeatSender) {
                $this->heartbeatSender->bindTaskToken((string) $resp->getTaskToken());
            }
        }
        try {
            $this->processor->process($message);
        } finally {
            // Nothing to teardown in the cooperative model
        }

        $terminal = $this->lastTerminalEvent($message->executionId, $message->activityId);
        if ($terminal instanceof ActivityCompleted) {
            $this->respondCompleted($resp, $terminal->result());

            return;
        }
        if ($terminal instanceof ActivityFailed) {
            $this->respondFailed($resp, $terminal);

            return;
        }
        if ($terminal instanceof ActivityCancelled) {
            $this->respondCanceled($resp);

            return;
        }

        throw new \RuntimeException('Activity processing finished without ActivityCompleted / ActivityFailed / ActivityCancelled in journal.');
    }

    /**
     * @return ActivityCompleted|ActivityFailed|ActivityCancelled|null
     */
    private function lastTerminalEvent(string $executionId, string $activityId): ActivityCompleted|ActivityFailed|ActivityCancelled|null
    {
        $last = null;
        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof ActivityCompleted && $event->activityId() === $activityId) {
                $last = $event;
            }
            if ($event instanceof ActivityFailed && $event->activityId() === $activityId) {
                $last = $event;
            }
            if ($event instanceof ActivityCancelled && $event->activityId() === $activityId) {
                $last = $event;
            }
        }

        return $last instanceof ActivityCompleted || $last instanceof ActivityFailed || $last instanceof ActivityCancelled ? $last : null;
    }

    private function respondCompleted(PollActivityTaskQueueResponse $poll, mixed $result): void
    {
        $req = new RespondActivityTaskCompletedRequest();
        $req->setTaskToken($poll->getTaskToken());
        $req->setNamespace($this->connection->namespace);
        $req->setIdentity($this->connection->identity.'-activity');
        $req->setResult(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($result)));

        $this->activityRpc->respondActivityTaskCompleted($req);
    }

    private function respondFailed(PollActivityTaskQueueResponse $poll, ActivityFailed $failed): void
    {
        $failure = new Failure();
        $failure->setMessage($failed->failureMessage());
        $failure->setSource('durable-php');
        $failure->setStackTrace($failed->failureTrace());
        $app = new ApplicationFailureInfo();
        $app->setType($failed->failureClass());
        $app->setNonRetryable(false);
        $failure->setApplicationFailureInfo($app);

        $req = new RespondActivityTaskFailedRequest();
        $req->setTaskToken($poll->getTaskToken());
        $req->setNamespace($this->connection->namespace);
        $req->setIdentity($this->connection->identity.'-activity');
        $req->setFailure($failure);

        $this->activityRpc->respondActivityTaskFailed($req);
    }

    private function respondCanceled(PollActivityTaskQueueResponse $poll): void
    {
        $req = new RespondActivityTaskCanceledRequest();
        $req->setTaskToken($poll->getTaskToken());
        $req->setNamespace($this->connection->namespace);
        $req->setIdentity($this->connection->identity.'-activity');

        $this->activityRpc->respondActivityTaskCanceled($req);
    }
}
