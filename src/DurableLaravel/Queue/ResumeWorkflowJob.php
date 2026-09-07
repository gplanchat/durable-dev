<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Bridge\Illuminate\Queue\ResumeLock;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A workflow resume, and only one at a time per execution.
 *
 * Two workers resuming the **same** execution both replay it, each believes it is discovering the
 * commands it produces, and those commands go out twice. The journal does not prevent it: it
 * faithfully records what it is given, the two of them included.
 *
 * **This job does not put itself back on the queue, it dispatches another one — and that is
 * deliberate.** `$this->release()` requires the `InteractsWithQueue` trait, hence
 * `illuminate/queue`, hence `symfony/process ^7.2`: the package would become irreconcilable with
 * the Symfony 6.4 line the repository's matrix still tests. But the argument is not only about
 * packaging — §1.2 measured that `release()` **consumes an attempt**, so much so that at
 * `--tries=5`, fifteen resumes out of twenty ended up in `failed_jobs` without having run a single
 * time: contention there became indistinguishable from a bug. A fresh job starts again with a fresh
 * budget of attempts, and `tries` recovers its meaning — the number of times a crash is tolerated.
 *
 * The price, and it is real: nothing bounds the deferral on the queue side any more. It is
 * `$deferrals` that bounds it here, and going over is noisy.
 */
final class ResumeWorkflowJob implements ShouldQueue
{
    public function __construct(
        public readonly ResumeWorkflowMessage $message,
        /** How many times this resume has already found the turn taken. */
        public readonly int $deferrals = 0,
    ) {}

    public function handle(
        ResumeWorkflowHandler $handler,
        ResumeLock $lock,
        QueueFactory $queue,
        ResumeDeferral $deferral,
    ): void {
        $replayed = $lock->tryAround($this->message->executionId, function () use ($handler): void {
            $handler($this->message);
        });

        if ($replayed) {
            return;
        }

        // The turn was taken: another worker is replaying this execution at this very moment.
        $deferral->defer($this, $queue);
    }
}
