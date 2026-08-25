<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Transport\ActivityMessage;
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
        $options = ActivityOptions::fromMetadata($message->metadata);

        // Redélivrance d'une tâche déjà tranchée : répondre depuis le journal sans réexécuter.
        if ($this->respondFromJournal($resp, $message, $options)) {
            return;
        }

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

        if ($this->respondFromJournal($resp, $message, $options)) {
            return;
        }

        throw new \RuntimeException('Activity processing finished without a terminal activity event in journal.');
    }

    /**
     * Répond au serveur à partir de l'issue terminale journalisée, si elle existe.
     *
     * @return bool false si l'activité n'a pas encore d'issue terminale
     */
    private function respondFromJournal(
        PollActivityTaskQueueResponse $resp,
        ActivityMessage $message,
        ?ActivityOptions $options,
    ): bool {
        $terminal = ActivityEventJournal::lastTerminalOutcome($this->eventStore, $message->executionId, $message->activityId);

        switch (true) {
            case $terminal instanceof ActivityCompleted:
                $this->respondCompleted($resp, $terminal->result());

                return true;
            case $terminal instanceof ActivityFailed:
                $this->respondFailed(
                    $resp,
                    $terminal->failureClass(),
                    $terminal->failureMessage(),
                    $terminal->failureTrace(),
                    self::isNonRetryable($terminal->failureClass(), $options),
                );

                return true;
            case $terminal instanceof ActivityCatastrophicFailure:
                // Un payload d'échec non sérialisable ne le deviendra pas à la tentative
                // suivante : inutile de laisser le serveur retenter. Sans cette branche, le
                // worker levait au lieu de répondre et la tâche restait sans réponse.
                $this->respondFailed(
                    $resp,
                    $terminal->exceptionClass(),
                    $terminal->exceptionMessage(),
                    '',
                    true,
                );

                return true;
            case $terminal instanceof ActivityCancelled:
                $this->respondCanceled($resp);

                return true;
            default:
                return false;
        }
    }

    /** True when the failed activity's exception type is declared non-retryable by its options. */
    private static function isNonRetryable(string $failureClass, ?ActivityOptions $options): bool
    {
        if (null === $options) {
            return false;
        }

        foreach ($options->nonRetryableExceptions as $type) {
            if ($failureClass === $type || is_a($failureClass, $type, true)) {
                return true;
            }
        }

        return false;
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

    private function respondFailed(
        PollActivityTaskQueueResponse $poll,
        string $failureClass,
        string $failureMessage,
        string $failureTrace,
        bool $nonRetryable,
    ): void {
        $failure = new Failure();
        $failure->setMessage($failureMessage);
        $failure->setSource('durable-php');
        $failure->setStackTrace($failureTrace);
        $app = new ApplicationFailureInfo();
        $app->setType($failureClass);
        // A failure whose exception type is listed in the activity's
        // nonRetryableExceptions must not be retried by the server.
        $app->setNonRetryable($nonRetryable);
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
