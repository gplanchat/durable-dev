<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Durable\Workflow\PendingUpdate;
use Temporal\Api\Update\V1\Request as UpdateRequest;

/**
 * An update received on the task, and what it takes to answer it.
 *
 * The acceptance must echo the original request back and say which message and which event it
 * answers — that is what the server then writes into `WORKFLOW_EXECUTION_UPDATE_ACCEPTED`, and
 * that is what makes the request readable again on replay.
 */
final class InboundUpdate
{
    public function __construct(
        public readonly PendingUpdate $pending,
        public readonly string $messageId,
        public readonly int $sequencingEventId,
        public readonly UpdateRequest $request,
    ) {}
}
