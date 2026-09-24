<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Messenger;

use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * One resume at a time per execution.
 *
 * Temporal serializes the tasks of a single workflow on the server side; the DBAL backend has no
 * server, so two consumers popping two resumes of the same execution would replay the same fiber
 * in parallel and write the same commands twice — duplicated activities, diverging journal. This
 * lock is the counterpart of that missing server.
 *
 * ponytail: blocking acquisition — the worker waits its turn rather than handing the message back.
 * Move to a reject + Messenger retry if the wait ties up too many workers.
 *
 * @see DUR030
 */
final class SingleResumeLockMiddleware implements MiddlewareInterface
{
    /** @var array<string, SharedLockInterface> executions whose lock this process holds right now */
    private array $held = [];

    /**
     * @param float $ttlSeconds how long a lock survives a worker that died holding it; it must
     *                          exceed the longest step of a pass (one `sync://` activity), or a
     *                          second worker replays in parallel (`durable.dbal.lock_ttl`)
     */
    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly float $ttlSeconds = 300.0,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $executionId = self::executionIdOf($envelope->getMessage());

        // The pass that holds the lock dispatches the next resume of the same execution on the
        // same bus: asking again for a lock this process holds waited for the TTL on every step
        // (#254). Every other pass still locks, sends included: a resume routed to no transport is
        // handled right there, without a ReceivedStamp, and needs the lock as much as a received one.
        if (null === $executionId || isset($this->held[$executionId])) {
            // Every message that crosses the bus during a pass is a step boundary: a sync activity,
            // the resume that follows it. Each one gives the held locks their TTL back, so the TTL
            // bounds one step, not the whole pass (#340). A lock lost in between throws here, and
            // the step does not run twice. ponytail: a pass that only replays, with no bus traffic,
            // is not refreshed; a heartbeat from the engine would be the next rung.
            $this->refreshHeld();
            $result = $stack->next()->handle($envelope, $stack);
            $this->refreshHeld();

            return $result;
        }

        $lock = $this->lockFactory->createLock('durable-resume-' . $executionId, $this->ttlSeconds);
        $lock->acquire(true);
        $this->held[$executionId] = $lock;

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            unset($this->held[$executionId]);
            $lock->release();
        }
    }

    private function refreshHeld(): void
    {
        foreach ($this->held as $lock) {
            $lock->refresh();
        }
    }

    private static function executionIdOf(object $message): ?string
    {
        return match (true) {
            $message instanceof ResumeWorkflowMessage,
            $message instanceof FireWorkflowTimersMessage => $message->executionId,
            default => null,
        };
    }
}
