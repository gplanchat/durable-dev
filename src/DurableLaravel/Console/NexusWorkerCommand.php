<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Console;

use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Illuminate\Console\Command;

/**
 * Drains the Nexus operations the cluster routes to this application.
 *
 * The bridge exposes only a `pollOnce()` — one turn, and nothing more: the loop, its stop criterion
 * and what it does with an error belong to the host. This one stops on `--max-time`, like
 * `durable:temporal-worker`, so that a supervisor can recycle it.
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
