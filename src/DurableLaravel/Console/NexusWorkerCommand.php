<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Console;

use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Illuminate\Console\Command;

/**
 * Draine les opérations Nexus que le cluster route vers cette application.
 *
 * Le pont n'expose qu'un `pollOnce()` — un tour, et rien de plus : la boucle, son critère d'arrêt
 * et ce qu'elle fait d'une erreur appartiennent à l'hôte. Celle-ci s'arrête sur `--max-time`, comme
 * `durable:temporal-worker`, pour qu'un superviseur puisse la recycler.
 */
final class NexusWorkerCommand extends Command
{
    protected $signature = 'durable:nexus-worker {--max-time=0 : Seconds to run before exiting, 0 for no limit}';

    protected $description = 'Serve the Nexus operations this application declares, polling the Temporal cluster';

    public function handle(TemporalNexusWorker $worker): int
    {
        $maxTime = (int) $this->option('max-time');
        $deadline = $maxTime > 0 ? microtime(true) + $maxTime : null;

        $this->info($deadline === null
            ? 'Serving Nexus operations. Ctrl-C to stop.'
            : \sprintf('Serving Nexus operations for %d seconds.', $maxTime));

        while ($deadline === null || microtime(true) < $deadline) {
            $worker->pollOnce();
        }

        return self::SUCCESS;
    }
}
