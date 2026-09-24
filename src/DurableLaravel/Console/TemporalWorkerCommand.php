<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Console;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Illuminate\Console\Command;

/**
 * The Temporal workers, one role per process: workflow tasks, or activity tasks.
 *
 * Under `illuminate`, everything goes through `php artisan queue:work` and this package adds no
 * command: that is the rule §3.2 gave itself. Temporal breaks it for a reason of its own — its
 * workflow and activity tasks are not in the application's queue, they are in the cluster, and
 * nobody else can take them out of it. Run both roles: without `--role=activity`, a run advances
 * up to its first activity and stops there (#355).
 *
 * The loops are the bridge's own, which know no framework. This command only gives them a stop
 * criterion and a place to run.
 */
final class TemporalWorkerCommand extends Command
{
    protected $signature = 'durable:temporal-worker
        {--role=workflow : Which task queue to drain: workflow or activity}
        {--max-time=0 : Seconds to run before exiting, 0 for no limit}';

    protected $description = 'Drain the Temporal workflow or activity tasks for the durable workflows this application declares';

    public function handle(TemporalConnection $connection): int
    {
        $role = (string) $this->option('role');
        if (!\in_array($role, ['workflow', 'activity'], true)) {
            $this->error(\sprintf('Unknown role "%s": expected workflow or activity. One process, one queue, one role.', $role));

            return self::FAILURE;
        }

        $this->line(\sprintf('Temporal %s, transport %s.', $connection->target, WorkflowServiceClientFactory::effectiveTransport($connection)));
        $maxTime = (int) $this->option('max-time');
        $deadline = $maxTime > 0 ? microtime(true) + $maxTime : null;
        $running = static fn(): bool => $deadline === null || microtime(true) < $deadline;

        $this->info($deadline === null
            ? \sprintf('Draining Temporal %s tasks. Ctrl-C to stop.', $role)
            : \sprintf('Draining Temporal %s tasks for %d seconds.', $role, $maxTime));

        if ('workflow' === $role) {
            $this->laravel->make(WorkflowTaskProcessor::class)->run($running);

            return self::SUCCESS;
        }

        $worker = $this->laravel->make(TemporalActivityWorker::class);
        while ($running()) {
            $worker->pollOnce();
        }

        return self::SUCCESS;
    }
}
