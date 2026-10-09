<?php

declare(strict_types=1);

/*
 * A minimal worker for TheDatabaseBackendRunsAWorkflowTest, run as its own process: it takes the
 * three queues in turn until <dir>/stop exists (or 60 s). Usage: drain.php <dir>
 *
 * `durable:worker` on SQL (#736) is the real one; this one has no retry policy, no requeue on a
 * transient error and no signal handling.
 */

use Gplanchat\Durable\Exception\ResumeArrivedBeforeItsOutcome;
use Gplanchat\Durable\MagentoBench\BenchRuntime;
use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\DurableModule\Runtime\TableQueue\Queues;

require __DIR__ . '/bootstrap.php';

$backend = BenchRuntime::factory()->database();
$deadline = microtime(true) + 60;

while (!is_file($argv[1] . '/stop') && microtime(true) < $deadline) {
    $idle = true;
    foreach ([Queues::RESUME, Queues::TIMER, Queues::ACTIVITY] as $queue) {
        $message = $backend->queue->take($queue);
        if (null === $message) {
            continue;
        }
        $idle = false;

        if (Queues::ACTIVITY === $queue) {
            $backend->activities->process(Queues::decode($message->body, ActivityMessage::class));
        } else {
            $resume = Queues::RESUME === $queue;
            $body = Queues::decode($message->body, $resume ? ResumeWorkflowMessage::class : FireWorkflowTimersMessage::class);
            if (!$backend->lock->tryAcquire($body->executionId)) {
                $backend->queue->enqueue($queue, $message->body, 0.1);
            } else {
                try {
                    $resume ? ($backend->resumeHandler)($body) : ($backend->timerHandler)($body);
                } catch (ResumeArrivedBeforeItsOutcome) {
                    $backend->queue->enqueue($queue, $message->body, 0.1);
                } catch (NexusUnsupportedByBackendException) {
                    // Already journalled as the run's failure: nothing to retry.
                } finally {
                    $backend->lock->release($body->executionId);
                }
            }
        }
        $backend->queue->ack($message);
    }
    if ($idle) {
        usleep(20_000);
    }
}
