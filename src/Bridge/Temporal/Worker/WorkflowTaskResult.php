<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Durable\Workflow\QueryHandlerRegistry;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Protocol\V1\Message;

/**
 * Value object returned by WorkflowTaskRunner::run().
 *
 * Carries the commands to send back to Temporal, the protocol messages that ride alongside them
 * (acceptance and response of an update — see {@see UpdateProtocol}), and the query handlers of
 * the execution (needed to answer queries after replay).
 */
final class WorkflowTaskResult
{
    /**
     * @param list<Command>             $commands
     * @param QueryHandlerRegistry|null $queryHandlers Populated after a non-empty poll; null for empty-poll heartbeats.
     * @param list<Message>             $messages      Protocol messages, empty until an update has been handled.
     */
    public function __construct(
        public readonly array $commands,
        /**
         * The execution's query handlers, to answer this poll's queries.
         *
         * The whole environment used to travel through here when only this plumbing was of use.
         */
        public readonly ?QueryHandlerRegistry $queryHandlers,
        public readonly array $messages = [],
    ) {}
}
