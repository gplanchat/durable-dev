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
 * (acceptance and response of an update — voir {@see UpdateProtocol}), and the query handlers of
 * the execution (needed to answer queries after replay).
 */
final class WorkflowTaskResult
{
    /**
     * @param list<Command>             $commands
     * @param QueryHandlerRegistry|null $queryHandlers Populated after a non-empty poll; null for empty-poll heartbeats.
     * @param list<Message>             $messages      Messages de protocole, vides tant qu'aucun update n'a été traité.
     */
    public function __construct(
        public readonly array $commands,
        /**
         * Les handlers de query de l'exécution, pour répondre aux queries de ce poll.
         *
         * L'environnement entier transitait ici alors que seule cette plomberie servait.
         */
        public readonly ?QueryHandlerRegistry $queryHandlers,
        public readonly array $messages = [],
    ) {}
}
