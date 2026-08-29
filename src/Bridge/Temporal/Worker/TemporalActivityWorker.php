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
    /** gRPC NOT_FOUND: the task token is stale (activity timed out / workflow already closed). */
    private const GRPC_NOT_FOUND = 5;

    public function __construct(
        private readonly WorkflowServiceActivityRpc $activityRpc,
        private readonly TemporalConnection $connection,
        private readonly ActivityMessageProcessor $processor,
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityHeartbeatSenderInterface $heartbeatSender,
    ) {}

    /**
     * Un long-poll ; si une tâche est reçue, traitement + réponse gRPC.
     */
    public function pollOnce(): void
    {
        $req = new PollActivityTaskQueueRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setTaskQueue(new TaskQueue(['name' => $this->connection->activityTaskQueue->name()]));
        $req->setIdentity($this->connection->identity . '-activity');

        $resp = $this->activityRpc->pollActivityTaskQueue($req);

        if ('' === $resp->getTaskToken()) {
            return;
        }

        $message = TemporalActivityScheduleInput::toActivityMessage($resp);
        $options = $message->options;

        // ⚠ **Redélivrance** d'une tâche déjà tranchée : répondre depuis le journal sans
        // réexécuter — mais une *reprise* n'est pas une redélivrance, et la question posée ici
        // doit porter sur cette livraison-ci. Interroger la dernière issue tout court faisait
        // répondre l'échec de la tentative 1 aux tentatives suivantes, sans jamais rappeler le
        // code de l'activité : trois tentatives consommées en deux secondes et une panne
        // passagère devenue définitive.
        if ($this->respondIfSettled(
            ActivityEventJournal::settledOutcomeForDelivery(
                $this->eventStore,
                $message->executionId,
                $message->activityId,
                $message->attempt,
            ),
            $resp,
            $options,
        )) {
            return;
        }

        if (null !== $options?->timeouts->heartbeat) {
            if ($this->heartbeatSender instanceof TemporalActivityHeartbeatSender) {
                $this->heartbeatSender->bindTaskToken((string) $resp->getTaskToken());
            }
        }

        try {
            $this->processor->process($message);
        } finally {
            // Nothing to teardown in the cooperative model
        }

        // Après traitement, la question est l'autre : **qu'est-ce que le processeur vient
        // d'écrire ?** Un échec en cours de reprise en fait partie — c'est lui qu'il faut rendre
        // au serveur pour qu'il ordonnance la tentative suivante.
        if ($this->respondIfSettled(
            ActivityEventJournal::lastTerminalOutcome($this->eventStore, $message->executionId, $message->activityId),
            $resp,
            $options,
        )) {
            return;
        }

        throw new \RuntimeException('Activity processing finished without a terminal activity event in journal.');
    }

    /**
     * Répond au serveur à partir d'une issue journalisée, si on lui en donne une.
     *
     * ⚠ L'issue est **passée en argument** plutôt que lue ici, et ce n'est pas un détail de
     * plomberie : les deux appels de `pollOnce()` ne posent pas la même question. Avant traitement,
     * « cette livraison a-t-elle déjà été tranchée ? » ; après, « qu'est-ce que le processeur vient
     * d'écrire ? ». Les confondre est précisément ce qui empêchait toute reprise d'activité.
     *
     * @return bool false quand il n'y a rien à répondre
     */
    private function respondIfSettled(
        ActivityCompleted|ActivityFailed|ActivityCatastrophicFailure|ActivityCancelled|null $terminal,
        PollActivityTaskQueueResponse $resp,
        ?ActivityOptions $options,
    ): bool {
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
        $req->setNamespace($this->connection->namespace->name());
        $req->setIdentity($this->connection->identity . '-activity');
        $req->setResult(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($result)));

        $this->ignoringStaleTask(fn() => $this->activityRpc->respondActivityTaskCompleted($req));
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
        $req->setNamespace($this->connection->namespace->name());
        $req->setIdentity($this->connection->identity . '-activity');
        $req->setFailure($failure);

        $this->ignoringStaleTask(fn() => $this->activityRpc->respondActivityTaskFailed($req));
    }

    private function respondCanceled(PollActivityTaskQueueResponse $poll): void
    {
        $req = new RespondActivityTaskCanceledRequest();
        $req->setTaskToken($poll->getTaskToken());
        $req->setNamespace($this->connection->namespace->name());
        $req->setIdentity($this->connection->identity . '-activity');

        $this->ignoringStaleTask(fn() => $this->activityRpc->respondActivityTaskCanceled($req));
    }

    /**
     * Run a RespondActivityTask* call, tolerating a stale task.
     *
     * Responding for a task whose workflow/activity already closed or timed out
     * yields gRPC NOT_FOUND (5); the server no longer tracks the task, so this
     * is benign and must not kill the poll loop. Mirrors the NOT_FOUND handling
     * already present in {@see \Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor::respond()}.
     */
    private function ignoringStaleTask(\Closure $respond): void
    {
        try {
            $respond();
        } catch (\RuntimeException $e) {
            if (self::GRPC_NOT_FOUND !== $e->getCode()) {
                throw $e;
            }
        }
    }
}
