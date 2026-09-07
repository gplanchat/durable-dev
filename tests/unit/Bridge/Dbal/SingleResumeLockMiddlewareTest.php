<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Gplanchat\Bridge\Dbal\Messenger\SingleResumeLockMiddleware;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * With no server to serialize the tasks of one execution, this lock is the only thing that keeps
 * two workers from replaying the same fiber in parallel. A lock taken but never released blocks
 * the execution for good; a lock that is never taken serves no purpose.
 *
 * @see DUR030
 */
final class SingleResumeLockMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(LockFactory::class)) {
            self::markTestSkipped('symfony/lock is not installed (a dependency of gplanchat/durable-bridge-dbal).');
        }
    }

    public function testLockIsHeldDuringHandlingAndReleasedAfter(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $middleware = new SingleResumeLockMiddleware($factory);

        $heldDuringHandling = null;
        $stack = $this->stackRunning(function () use ($factory, &$heldDuringHandling): void {
            // Non-blocking acquisition from "another worker": it must fail.
            $heldDuringHandling = !$factory->createLock('durable-resume-exec-1')->acquire(false);
        });

        $middleware->handle(new Envelope(new ResumeWorkflowMessage('exec-1')), $stack);

        self::assertTrue($heldDuringHandling, 'the lock must be held during the resume');
        self::assertTrue(
            $factory->createLock('durable-resume-exec-1')->acquire(false),
            'the lock must be released after the resume',
        );
    }

    public function testLockIsReleasedWhenHandlingThrows(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $middleware = new SingleResumeLockMiddleware($factory);

        $stack = $this->stackRunning(static function (): void {
            throw new \RuntimeException('boom');
        });

        try {
            $middleware->handle(new Envelope(new ResumeWorkflowMessage('exec-1')), $stack);
            self::fail('the handler exception must propagate');
        } catch (\RuntimeException) {
        }

        self::assertTrue($factory->createLock('durable-resume-exec-1')->acquire(false));
    }

    public function testUnrelatedMessagesAreNotLocked(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $middleware = new SingleResumeLockMiddleware($factory);

        $passedThrough = false;
        $stack = $this->stackRunning(static function () use (&$passedThrough): void {
            $passedThrough = true;
        });

        $middleware->handle(new Envelope(new \stdClass()), $stack);

        self::assertTrue($passedThrough);
    }

    private function stackRunning(callable $duringHandling): StackInterface
    {
        $next = new class ($duringHandling) implements MiddlewareInterface {
            public function __construct(private $duringHandling) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->duringHandling)();

                return $envelope;
            }
        };

        return new class ($next) implements StackInterface {
            public function __construct(private readonly MiddlewareInterface $next) {}

            public function next(): MiddlewareInterface
            {
                return $this->next;
            }
        };
    }
}
