<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Une reprise de workflow, sur la même file que les activités.
 *
 * Le job porte le message et rien d'autre. Ce qui le rejoue est le runtime du cœur, et ce qui
 * empêche deux workers de le rejouer en même temps est `Queue\ResumeLock` — que §4 posera ici,
 * dans la forme que §1.2 a mesurée.
 */
final class ResumeWorkflowJob implements ShouldQueue
{
    public function __construct(
        public readonly ResumeWorkflowMessage $message,
    ) {}

    /**
     * Le rejeu est celui du cœur, pas celui de ce paquet.
     *
     * `ResumeWorkflowHandler` a quitté le bundle Symfony pour le cœur précisément pour qu'un hôte
     * sans bus de messages puisse le rendre — c'est le même code qui reprend une exécution ici et
     * sous Symfony, et c'est ce qui rend « le même workflow, sans modification » vérifiable jusque
     * dans la reprise.
     */
    public function handle(ResumeWorkflowHandler $handler): void
    {
        $handler($this->message);
    }
}
