<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Durable\WorkflowEnvironment;
use Temporal\Api\Command\V1\Command;

/**
 * Value object returned by WorkflowTaskRunner::run().
 *
 * Carries the commands to send back to Temporal and the live WorkflowEnvironment
 * (needed for query handler resolution after replay).
 */
final class WorkflowTaskResult
{
    /**
     * @param list<Command>          $commands
     * @param WorkflowEnvironment|null $environment Populated after a non-empty poll; null for empty-poll heartbeats.
     * @param list<array{pending: \Gplanchat\Durable\Workflow\PendingUpdate, message: \Temporal\Api\Protocol\V1\Message, request: \Temporal\Api\Update\V1\Request}> $updates
     *        Les updates apportés par cette tâche, avec leur issue une fois la passe jouée.
     */
    public function __construct(
        public readonly array $commands,
        public readonly ?WorkflowEnvironment $environment,
        public readonly array $updates = [],
    ) {}
}
