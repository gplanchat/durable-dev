<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\DurableBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Does what the bundle promises really insert anything into a bus.
 *
 * The DBAL backend's resume lock was registered with `->addTag('messenger.middleware')`.
 * That tag does not exist in Symfony: nothing calls `findTaggedServiceIds()` on it, and
 * `UnusedTagsPass` does not know it. The service was therefore defined and **installed in no bus**,
 * silently, while the documentation promises it is installed automatically and that it is the
 * backend's only guard against two concurrent resumes of the same execution — duplicated
 * activities, forked journal.
 *
 * The test names no compiler pass: it plays the ones the bundle registers and looks at the
 * `<busId>.middleware` parameter, the only place where Messenger reads its stack. A renamed or
 * replaced pass does not make it pass by accident.
 *
 * @see DUR030
 */
final class DurableMiddlewareReachesTheBusTest extends TestCase
{
    public function testTheDbalResumeLockIsInstalledInTheBus(): void
    {
        $middleware = $this->middlewareOfBusAfterCompilation(['event_store' => ['type' => 'dbal']]);

        self::assertContains('durable.dbal.single_resume_lock', $middleware);
    }

    public function testTheProfilerMiddlewareStaysInstalled(): void
    {
        $middleware = $this->middlewareOfBusAfterCompilation(['event_store' => ['type' => 'dbal']]);

        self::assertContains('durable.messenger.middleware.workflow_run_dispatch_profiler', $middleware);
    }

    public function testWithoutTheDbalEventStoreNoLockIsInstalled(): void
    {
        $middleware = $this->middlewareOfBusAfterCompilation(['event_store' => ['type' => 'in_memory']]);

        self::assertNotContains('durable.dbal.single_resume_lock', $middleware);
    }

    public function testTheTraceableMiddlewareKeepsItsPlaceInFront(): void
    {
        $middleware = $this->middlewareOfBusAfterCompilation(
            ['event_store' => ['type' => 'dbal']],
            existing: [['id' => 'traceable'], ['id' => 'send_message']],
        );

        self::assertSame('traceable', $middleware[0]);
    }

    /**
     * @param array<string, mixed>                                    $config
     * @param list<array{id?: string, arguments?: array<int, mixed>}> $existing
     *
     * @return list<string>
     */
    private function middlewareOfBusAfterCompilation(array $config, array $existing = [['id' => 'send_message']]): array
    {
        $container = new ContainerBuilder();
        (new DurableExtension())->load([$config], $container);

        // What FrameworkExtension lays down for each declared bus, and the only thing that
        // MessengerPass reads back afterwards.
        $container->register('messenger.bus.default')->addTag('messenger.bus');
        $container->setParameter('messenger.bus.default.middleware', $existing);

        (new DurableBundle())->build($container);
        foreach ($container->getCompilerPassConfig()->getBeforeOptimizationPasses() as $pass) {
            $pass->process($container);
        }

        /** @var list<array{id?: string}> $stack */
        $stack = $container->getParameter('messenger.bus.default.middleware');

        return array_values(array_filter(array_column($stack, 'id')));
    }
}
