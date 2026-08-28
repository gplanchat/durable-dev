<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

/**
 * Un minuteur, sur le délai que la file porte déjà.
 *
 * Réveiller une exécution « dans n millisecondes » est une reprise différée, et rien d'autre : le
 * port ne demande pas un mécanisme séparé. Le pendant Symfony obtient la même chose d'un
 * `DelayStamp` ; ici c'est `later()`, arrondi **au-dessus** parce qu'attendre moins que demandé est
 * la seule erreur qui compte — un workflow réveillé trop tôt reprend avant son échéance.
 */
final class LaravelWorkflowTimerDispatcher implements WorkflowTimerDispatcher
{
    public function __construct(
        private readonly QueueFactory $queue,
        private readonly ?string $connection = null,
        private readonly ?string $queueName = null,
    ) {}

    public function dispatchTimerFire(string $executionId, int $delayMs = 0): void
    {
        $job = new ResumeWorkflowJob(new ResumeWorkflowMessage($executionId));
        $connection = $this->queue->connection($this->connection);

        if ($delayMs > 0) {
            $connection->later((int) ceil($delayMs / 1000), $job, '', $this->queueName);

            return;
        }

        $connection->push($job, '', $this->queueName);
    }
}
