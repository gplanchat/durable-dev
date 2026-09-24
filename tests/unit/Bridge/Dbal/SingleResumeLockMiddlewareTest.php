<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Gplanchat\Bridge\Dbal\Messenger\SingleResumeLockMiddleware;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

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

    public function testAResumeDispatchedWhileHandlingOneDoesNotWaitForItsOwnLock(): void
    {
        // #254: the handler dispatches the next resume of the same execution, on the same bus.
        // Received again (a sync transport), it asked for the lock its own pass held, and waited
        // for the TTL to run out. The store below refuses instead of waiting, so a regression fails
        // here at once rather than hanging the suite.
        $factory = self::failFastFactory();
        $nested = 0;
        $bus = null;
        $bus = new MessageBus([
            new SingleResumeLockMiddleware($factory, 2.0),
            new HandleMessageMiddleware(new HandlersLocator([
                ResumeWorkflowMessage::class => [static function (ResumeWorkflowMessage $message) use (&$bus, &$nested): void {
                    if (0 === $nested++) {
                        $bus->dispatch(new Envelope(new ResumeWorkflowMessage('exec-1'), [new ReceivedStamp('sync')]));
                    }
                }],
            ])),
        ]);

        $started = microtime(true);
        $bus->dispatch(new Envelope(new ResumeWorkflowMessage('exec-1'), [new ReceivedStamp('sync')]));

        self::assertSame(2, $nested);
        self::assertLessThan(1.0, microtime(true) - $started, 'the nested resume must not wait for the TTL');
        self::assertTrue($factory->createLock('durable-resume-exec-1')->acquire(false), 'and the lock is released after both');
    }

    public function testAResumeHandledWithoutATransportIsStillSerialised(): void
    {
        // Routed to no transport, a resume is handled on the dispatching call, with no
        // ReceivedStamp: it must still wait its turn behind another holder.
        $factory = self::failFastFactory();
        $other = $factory->createLock('durable-resume-exec-1');
        $other->acquire(false);

        $this->expectException(\Symfony\Component\Lock\Exception\LockAcquiringException::class);

        (new SingleResumeLockMiddleware($factory, 2.0))->handle(new Envelope(new ResumeWorkflowMessage('exec-1')), $this->stackRunning(static function (): void {}));
    }

    /**
     * A lock store that refuses instead of waiting: a regression fails the test at once rather
     * than hanging the suite on a blocking acquisition that nothing will ever release.
     */
    private static function failFastFactory(): LockFactory
    {
        return new LockFactory(new class extends InMemoryStore {
            public function save(Key $key): void
            {
                try {
                    parent::save($key);
                } catch (LockConflictedException) {
                    throw new \LogicException(\sprintf('"%s" would wait for a lock that is already held.', $key));
                }
            }
        });
    }

    private function stackRunning(callable $duringHandling): StackInterface
    {
        $next = new class ($duringHandling) implements MiddlewareInterface {
            /** @param callable(): mixed $duringHandling */
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
