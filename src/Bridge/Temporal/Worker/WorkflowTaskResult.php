<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Durable\WorkflowEnvironment;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Protocol\V1\Message;

/**
 * Value object returned by WorkflowTaskRunner::run().
 *
 * Carries the commands to send back to Temporal, the protocol messages that ride alongside them
 * (acceptance and response of an update — voir {@see UpdateProtocol}), and the live
 * WorkflowEnvironment (needed for query handler resolution after replay).
 */
final class WorkflowTaskResult
{
    /**
     * @param list<Command>            $commands
     * @param WorkflowEnvironment|null $environment Populated after a non-empty poll; null for empty-poll heartbeats.
     * @param list<Message>            $messages    Messages de protocole, vides tant qu'aucun update n'a été traité.
     */
    public function __construct(
        public readonly array $commands,
        public readonly ?WorkflowEnvironment $environment,
        public readonly array $messages = [],
    ) {}
}
