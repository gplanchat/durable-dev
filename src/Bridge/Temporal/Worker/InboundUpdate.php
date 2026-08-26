<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Durable\Workflow\PendingUpdate;
use Temporal\Api\Update\V1\Request as UpdateRequest;

/**
 * Un update reçu sur la tâche, et ce qu'il faut pour y répondre.
 *
 * L'acceptation doit réécho la requête d'origine et dire à quel message et à quel événement elle
 * répond — c'est ce que le serveur écrit ensuite dans `WORKFLOW_EXECUTION_UPDATE_ACCEPTED`, et
 * c'est ce qui rend la requête relisible au replay.
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
