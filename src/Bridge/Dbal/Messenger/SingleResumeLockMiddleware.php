<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Messenger;

use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Symfony\Component\Lock\LockFactory;
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
    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly float $ttlSeconds = 300.0,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $executionId = self::executionIdOf($envelope->getMessage());
        if (null === $executionId) {
            return $stack->next()->handle($envelope, $stack);
        }

        $lock = $this->lockFactory->createLock('durable-resume-' . $executionId, $this->ttlSeconds);
        $lock->acquire(true);

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $lock->release();
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
