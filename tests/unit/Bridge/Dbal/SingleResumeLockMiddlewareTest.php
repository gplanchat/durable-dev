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
 * Sans serveur pour sérialiser les tâches d'une exécution, ce verrou est la seule chose qui
 * empêche deux workers de rejouer le même fiber en parallèle. Un verrou pris mais jamais relâché
 * bloque l'exécution pour de bon ; un verrou jamais pris ne sert à rien.
 *
 * @see DUR030
 */
final class SingleResumeLockMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(LockFactory::class)) {
            self::markTestSkipped('symfony/lock non installé (dépendance de gplanchat/durable-bridge-dbal).');
        }
    }

    public function testLockIsHeldDuringHandlingAndReleasedAfter(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $middleware = new SingleResumeLockMiddleware($factory);

        $heldDuringHandling = null;
        $stack = $this->stackRunning(function () use ($factory, &$heldDuringHandling): void {
            // Acquisition non bloquante depuis « un autre worker » : elle doit échouer.
            $heldDuringHandling = !$factory->createLock('durable-resume-exec-1')->acquire(false);
        });

        $middleware->handle(new Envelope(new ResumeWorkflowMessage('exec-1')), $stack);

        self::assertTrue($heldDuringHandling, 'le verrou doit être tenu pendant la reprise');
        self::assertTrue(
            $factory->createLock('durable-resume-exec-1')->acquire(false),
            'le verrou doit être relâché après la reprise',
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
            self::fail('l’exception du handler doit remonter');
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
