<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CheckExceptionOnInvalidReferenceBehaviorPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * What the profiler costs when nobody is looking at it.
 *
 * The observer is injected into `ExecutionRuntime`, `ExecutionEngine` and
 * `ActivityMessageProcessor`: it is on the hot path of the execution, not on that of the debug
 * page. A worker has no request, so nothing request-scoped empties its trace.
 *
 * Three guards: the `kernel.reset` tag empties it between two Messenger messages, the trace's own
 * bound holds on a Temporal worker, where `kernel.reset` never fires, and the plain absence of the
 * profiler bounds production.
 */
final class DurableProfilerWiringTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=demo-shop&tls=0';

    public function testTheTraceIsResettableBetweenTwoMessagesOfAWorker(): void
    {
        $definition = $this->load(debug: true)->getDefinition('durable.execution_trace');

        self::assertArrayHasKey(
            'kernel.reset',
            $definition->getTags(),
            'without this tag, services_resetter ignores the trace and a worker accumulates it without bound',
        );
        self::assertSame(
            'reset',
            $definition->getTag('kernel.reset')[0]['method'] ?? null,
            'and the resetter needs the method name',
        );
    }

    public function testNoKernelRequestListenerEmptiesTheTrace(): void
    {
        // The trace bounds itself; a listener at priority 1024 on every request did nothing more.
        foreach ($this->load(debug: true)->getDefinitions() as $id => $definition) {
            self::assertStringNotContainsString('ResetDurableProfilerListener', $id . ' ' . $definition->getClass(), $id);
        }
    }

    public function testOutsideDebugNoCollectorIsRegistered(): void
    {
        $container = $this->load(debug: false);

        self::assertFalse(
            $container->has('durable.execution_trace'),
            'the profile trace has no business in production',
        );

        foreach ($container->getDefinitions() as $id => $definition) {
            self::assertArrayNotHasKey(
                'data_collector',
                $definition->getTags(),
                \sprintf('%s must not collect outside debug', $id),
            );
        }
    }

    /**
     * The observation contract stays satisfied: the three hot-path services receive it by
     * injection, and a container that did not provide it would no longer compile.
     */
    public function testOutsideDebugTheObserverIsANullObject(): void
    {
        $container = $this->load(debug: false);

        self::assertTrue($container->hasAlias(WorkflowExecutionObserverInterface::class));

        $target = (string) $container->getAlias(WorkflowExecutionObserverInterface::class);
        self::assertSame(
            \Gplanchat\Durable\Debug\NullWorkflowExecutionObserver::class,
            $container->getDefinition($target)->getClass(),
        );
    }

    public function testInDebugTheObserverIsTheTrace(): void
    {
        $container = $this->load(debug: true);

        self::assertSame(
            'durable.execution_trace',
            (string) $container->getAlias(WorkflowExecutionObserverInterface::class),
        );
    }

    /**
     * Removing the profiler from production is not enough: nothing may claim it any more.
     *
     * `TemporalWorkflowResumeDispatcher` received `durable.execution_trace` through a bare
     * reference. The service being no longer registered outside debug, the container of a
     * production application configured for native Temporal no longer compiled — and no test saw
     * it, all of them loading an empty configuration, hence never building that branch.
     */
    public function testOutsideDebugATemporalApplicationStillCompiles(): void
    {
        $container = $this->load(debug: false, config: ['temporal' => ['dsn' => self::DSN]]);

        $arguments = $container->getDefinition(WorkflowResumeDispatcher::class)->getArguments();
        $trace = $arguments[3] ?? null;

        self::assertInstanceOf(Reference::class, $trace);
        self::assertSame('durable.execution_trace', (string) $trace);
        self::assertSame(
            ContainerInterface::NULL_ON_INVALID_REFERENCE,
            $trace->getInvalidBehavior(),
            'a bare reference to a service absent outside debug fails the compilation',
        );

        self::assertNotContains(
            'durable.execution_trace',
            self::missingServices($container),
            'the production container must no longer claim a service that debug alone registers',
        );
    }

    public function testInDebugTheSameContainerReceivesTheRealTrace(): void
    {
        $container = $this->load(debug: true, config: ['temporal' => ['dsn' => self::DSN]]);

        self::assertTrue($container->has('durable.execution_trace'));
        self::assertSame(
            'durable.execution_trace',
            (string) $container->getDefinition(WorkflowResumeDispatcher::class)->getArgument(3),
        );
    }

    /**
     * `UPGRADE.md` prescribes this escape hatch to applications that want to observe in
     * production: implement the contract, alias the interface. It did not work — the extension's
     * `setAlias()` overwrote the application's, whose definitions are nevertheless already there
     * when the extension loads.
     */
    #[DataProvider('environments')]
    public function testAnAliasOfTheApplicationIsNotOverwritten(bool $debug): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->register('app.observer', \stdClass::class);
        $container->setAlias(WorkflowExecutionObserverInterface::class, 'app.observer');

        (new DurableExtension())->load([[]], $container);

        self::assertSame(
            'app.observer',
            (string) $container->getAlias(WorkflowExecutionObserverInterface::class),
        );
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function environments(): iterable
    {
        yield 'debug' => [true];
        yield 'production' => [false];
    }

    /**
     * The identifiers a container claims without having them.
     *
     * The bundle alone does not compile: it legitimately references services FrameworkBundle
     * provides (`messenger.default_bus`, …). So every missing one is declared synthetic and the
     * pass is run again, until the upstream pass goes through — what remains is the exact list of
     * what the bundle expects from outside. A service **of ours** in that list is a bug.
     *
     * @return list<string>
     */
    private static function missingServices(ContainerBuilder $container): array
    {
        $missing = [];
        $pass = new CheckExceptionOnInvalidReferenceBehaviorPass();

        for ($i = 0; $i < 100; ++$i) {
            try {
                $pass->process($container);

                return $missing;
            } catch (ServiceNotFoundException $e) {
                $id = $e->getId();
                if (null === $id || \in_array($id, $missing, true)) {
                    throw $e;
                }
                $missing[] = $id;
                $container->register($id, \stdClass::class)->setSynthetic(true);
            }
        }

        self::fail('the verification pass does not converge');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(bool $debug, array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        (new DurableExtension())->load([$config], $container);

        return $container;
    }
}
