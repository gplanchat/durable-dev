<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Console;

use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Illuminate\Console\Command;

/**
 * The workflow task worker, for the Temporal backend — and the only one this package adds.
 *
 * Under `illuminate`, everything goes through `php artisan queue:work` and this package adds no
 * command: that is the rule §3.2 gave itself. Temporal breaks it for a reason of its own — its
 * workflow tasks are not in the application's queue, they are in the cluster, and nobody else can
 * take them out of it.
 *
 * The loop is the bridge's own, `WorkflowTaskProcessor::run()`, which knows no framework. This
 * command only gives it a stop criterion and a place to run it.
 */
final class TemporalWorkerCommand extends Command
{
    protected $signature = 'durable:temporal-worker {--max-time=0 : Seconds to run before exiting, 0 for no limit}';

    protected $description = 'Drain Temporal workflow tasks for the durable workflows this application declares';

    public function handle(WorkflowTaskProcessor $processor): int
    {
        $maxTime = (int) $this->option('max-time');
        $deadline = $maxTime > 0 ? microtime(true) + $maxTime : null;

        $this->info($deadline === null
            ? 'Draining Temporal workflow tasks. Ctrl-C to stop.'
            : \sprintf('Draining Temporal workflow tasks for %d seconds.', $maxTime));

        $processor->run(static fn(): bool => $deadline === null || microtime(true) < $deadline);

        return self::SUCCESS;
    }
}
