<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Temporal\Api\Workflowservice\V1\RecordActivityTaskHeartbeatRequest;

/**
 * Cooperative Temporal heartbeat sender.
 *
 * Called synchronously by activity handler code (or the activity worker between retries).
 * Sends RecordActivityTaskHeartbeat and caches the cancel_requested state.
 *
 * Replaces TemporalActivityHeartbeatFork (pcntl_fork-based, forbidden per DUR027).
 * Activity code is responsible for calling sendHeartbeat() at appropriate intervals.
 */
final class TemporalActivityHeartbeatSender implements ActivityHeartbeatSenderInterface
{
    private bool $cancelRequested = false;
    private ?string $taskToken = null;

    public function __construct(
        private readonly WorkflowServiceActivityRpc $activityRpc,
        private readonly TemporalConnection $connection,
    ) {
    }

    /**
     * Binds this sender to a specific activity task token.
     * Must be called before sendHeartbeat() for each activity task.
     */
    public function bindTaskToken(string $taskToken): void
    {
        $this->taskToken = $taskToken;
        $this->cancelRequested = false;
    }

    public function sendHeartbeat(mixed $details = null): bool
    {
        if (null === $this->taskToken || '' === $this->taskToken) {
            return false;
        }

        try {
            $req = new RecordActivityTaskHeartbeatRequest();
            $req->setTaskToken($this->taskToken);
            $req->setNamespace($this->connection->namespace);
            $req->setIdentity($this->connection->identity.'-activity');
            if (null !== $details) {
                $req->setDetails(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($details)));
            }

            $resp = $this->activityRpc->recordActivityTaskHeartbeat($req);
            $this->cancelRequested = $resp->getCancelRequested();
        } catch (\Throwable) {
            // Heartbeat failures are non-fatal — the activity continues running.
            // Temporal will time out the activity if heartbeat_timeout elapses without a heartbeat.
        }

        return $this->cancelRequested;
    }

    public function isCancellationRequested(): bool
    {
        return $this->cancelRequested;
    }
}
