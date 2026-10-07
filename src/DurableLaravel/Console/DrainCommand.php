<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Console;

use Gplanchat\Durable\Laravel\Queue\InProcessWorkflowResumeDispatcher;
use Illuminate\Console\Command;

/**
 * Drives the runs this process has queued on the memory backend (#881).
 *
 * The journal of that backend lives in the process: the command drains only what the process that
 * runs it has queued. The provider registers it in a console process only; from code, call
 * `InProcessWorkflowResumeDispatcher::drain()` after `dispatchNewWorkflowRun()`.
 */
final class DrainCommand extends Command
{
    protected $signature = 'durable:drain';

    protected $description = 'Drive the workflow runs this process has queued on the memory backend';

    public function handle(InProcessWorkflowResumeDispatcher $dispatcher): int
    {
        $dispatcher->drain();

        return self::SUCCESS;
    }
}
