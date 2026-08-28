<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Illuminate\Contracts\Queue\Factory as QueueFactory;

/**
 * Ce qu'on fait d'une reprise dont le tour est pris : la reposer plus tard, un nombre borné de
 * fois.
 *
 * **Le délai est un réglage, et §1.5 dit pourquoi.** Sur une exécution froide — beaucoup de
 * workflows en vol, une poignée de workers — les collisions sont une erreur d'arrondi : 0,6 % à
 * seize exécutions par worker. Sur une exécution chaude, celle qu'un signal ou un minuteur réveille
 * sans cesse, elles montent à 98,8 %, et là le délai **est** la latence : une seconde de report a
 * transformé 32 s de travail en 148 s d'horloge.
 *
 * **Et le plafond est bruyant, pas silencieux.** Un report sans fin ressemble à une exécution qui
 * avance ; une exception nomme l'exécution et le nombre d'essais, ce qui se voit dans
 * `failed_jobs`.
 */
final class ResumeDeferral
{
    public function __construct(
        private readonly int $backoffSeconds = 1,
        private readonly int $maxDeferrals = 50,
        private readonly ?string $connection = null,
        private readonly ?string $queueName = null,
    ) {}

    public function defer(ResumeWorkflowJob $job, QueueFactory $queue): void
    {
        if ($job->deferrals >= $this->maxDeferrals) {
            throw new \RuntimeException(\sprintf(
                'Durable: gave up resuming %s — the per-execution lock was held on %d consecutive '
                . 'attempts. Either a worker died holding it (the lock TTL releases it), or this '
                . 'execution is resumed faster than it replays; raise durable.lock.backoff.',
                $job->message->executionId,
                $job->deferrals,
            ));
        }

        $queue->connection($this->connection)->later(
            $this->backoffSeconds,
            new ResumeWorkflowJob($job->message, $job->deferrals + 1),
            '',
            $this->queueName,
        );
    }
}
