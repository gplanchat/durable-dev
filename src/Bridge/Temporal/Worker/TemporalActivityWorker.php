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
 * Polls the Temporal activity queue, runs the {@see ActivityMessageProcessor} path (journal + resume)
 * and answers the server ({@code RespondActivityTaskCompleted} / {@code RespondActivityTaskFailed}).
 *
 * To be used with tasks scheduled by {@see \Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor}
 * and a {@see \Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput} input.
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
     * One long poll; if a task is received, processing + gRPC response.
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

        // ⚠ **Redelivery** of an already settled task: answer from the journal without running
        // again — but a *retry* is not a redelivery, and the question asked here must bear on
        // this delivery. Asking for the last outcome plain and simple made the failure of
        // attempt 1 be answered to the following attempts, without ever calling the activity
        // code again: three attempts burnt in two seconds and a transient outage turned
        // permanent.
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

        // After processing, the question is the other one: **what has the processor just
        // written?** A failure during a retry is part of it — that is what must be handed back
        // to the server so that it schedules the next attempt.
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
     * Answers the server from a journalled outcome, if one is given to it.
     *
     * ⚠ The outcome is **passed as an argument** rather than read here, and that is no plumbing
     * detail: the two calls in `pollOnce()` do not ask the same question. Before processing, "has
     * this delivery already been settled?"; after, "what has the processor just written?".
     * Confusing the two is precisely what prevented any activity retry.
     *
     * @return bool false when there is nothing to answer
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
                // A failure payload that cannot be serialized will not become serializable on
                // the next attempt: no point letting the server retry. Without this branch, the
                // worker raised instead of answering and the task stayed unanswered.
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
