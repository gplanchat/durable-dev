<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Gplanchat\Bridge\Dbal\Messenger\SingleResumeLockMiddleware;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
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

    public function testTheLockOutlivesItsTtlWhileTheStepsOfOnePassKeepComing(): void
    {
        // R-8: a pass whose steps (sync activities) add up past the TTL. Each step crosses the
        // bus, and each crossing is a step boundary where the held lock gets its TTL back. A
        // second consumer must not take the lock in the middle of the pass.
        $clock = new \ArrayObject(['now' => 0.0]);
        $store = self::storeOn($clock);
        $factory = new LockFactory($store);
        $secondConsumerGotIt = null;
        $bus = null;
        $bus = new MessageBus([
            new SingleResumeLockMiddleware($factory, 1.0),
            new HandleMessageMiddleware(new HandlersLocator([
                ResumeWorkflowMessage::class => [static function () use (&$bus): void {
                    foreach ([1, 2, 3] as $step) {
                        $bus->dispatch(new Envelope((object) ['step' => $step], [new ReceivedStamp('sync')]));
                    }
                }],
                \stdClass::class => [static function (\stdClass $activity) use ($clock, $factory, &$secondConsumerGotIt): void {
                    $clock['now'] += 0.6;
                    if (3 === $activity->step) {
                        $secondConsumerGotIt = $factory->createLock('durable-resume-exec-1', 1.0)->acquire(false);
                    }
                }],
            ])),
        ]);

        $bus->dispatch(new Envelope(new ResumeWorkflowMessage('exec-1'), [new ReceivedStamp('sync')]));

        self::assertFalse($secondConsumerGotIt, '1.8 s into a pass with a 1 s TTL, the lock must still be held');
    }

    public function testAStepDoesNotStartOnceThePassHasLostItsLock(): void
    {
        // The pass outran its TTL and another consumer took the execution: running the next step
        // would run it twice. The pass stops at the boundary instead.
        $clock = new \ArrayObject(['now' => 0.0]);
        $factory = new LockFactory(self::storeOn($clock));
        $stepRan = false;
        $other = $factory->createLock('durable-resume-exec-1', 1.0);
        $bus = null;
        $bus = new MessageBus([
            new SingleResumeLockMiddleware($factory, 1.0),
            new HandleMessageMiddleware(new HandlersLocator([
                ResumeWorkflowMessage::class => [static function () use (&$bus, $clock, $other): void {
                    $clock['now'] += 1.5;
                    self::assertTrue($other->acquire(false), 'past the TTL, another consumer takes the execution');
                    $bus->dispatch(new Envelope(new \stdClass(), [new ReceivedStamp('sync')]));
                }],
                \stdClass::class => [static function () use (&$stepRan): void {
                    $stepRan = true;
                }],
            ])),
        ]);

        try {
            $bus->dispatch(new Envelope(new ResumeWorkflowMessage('exec-1'), [new ReceivedStamp('sync')]));
            self::fail('the pass must stop once its lock is lost');
        } catch (\Throwable) {
        }

        self::assertFalse($stepRan, 'the step must not run without the lock');
        self::assertTrue($other->isAcquired(), 'and the stale pass must not take the lock back');
    }

    /**
     * A lock store whose keys expire on a clock the test moves, as a PDO or Redis store would
     * expire them on the wall clock. InMemoryStore never expires anything, so it cannot tell a
     * refreshed lock from a stale one.
     *
     * @param \ArrayObject<string, float> $clock
     */
    private static function storeOn(\ArrayObject $clock): PersistingStoreInterface
    {
        return new class ($clock) implements PersistingStoreInterface {
            /** @var array<string, array{object, float}> resource => [holder key, expiry] */
            private array $locks = [];

            /** @param \ArrayObject<string, float> $clock */
            public function __construct(private readonly \ArrayObject $clock) {}

            public function save(Key $key): void
            {
                $this->claim($key, \INF);
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                $this->claim($key, $this->clock['now'] + $ttl);
            }

            public function delete(Key $key): void
            {
                if ($this->exists($key)) {
                    unset($this->locks[(string) $key]);
                }
            }

            public function exists(Key $key): bool
            {
                [$holder, $expiry] = $this->locks[(string) $key] ?? [null, 0.0];

                return $holder === $key && $expiry > $this->clock['now'];
            }

            private function claim(Key $key, float $expiry): void
            {
                [$holder, $until] = $this->locks[(string) $key] ?? [null, 0.0];
                if (null !== $holder && $holder !== $key && $until > $this->clock['now']) {
                    throw new LockConflictedException();
                }
                $this->locks[(string) $key] = [$key, $expiry];
            }
        };
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
