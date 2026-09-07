<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection\Compiler;

use Gplanchat\Bridge\Temporal\Messenger\TemporalTransportFactory;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\DurableTemporalTransportFactoryPass;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Checks that the compiler pass injects the right services into TemporalTransportFactory.
 *
 * Regression: the pass used to inject EventStoreInterface at index 3 instead of WorkflowRegistry,
 * causing a type error when the container was initialized.
 *
 * @internal
 */
#[CoversClass(DurableTemporalTransportFactoryPass::class)]
final class DurableTemporalTransportFactoryPassTest extends TestCase
{
    public function testPassIsNoOpWhenTemporalTransportFactoryIsAbsent(): void
    {
        $container = new ContainerBuilder();
        // No TemporalTransportFactory registered → the pass does nothing.
        $container->register('durable.temporal.connection', \stdClass::class);

        (new DurableTemporalTransportFactoryPass())->process($container);

        // No exception raised — the pass did ignore the absence of the service.
        $this->assertTrue(true);
    }

    public function testPassIsNoOpWhenTemporalConnectionIsAbsent(): void
    {
        $container = new ContainerBuilder();
        $container->register(TemporalTransportFactory::class, TemporalTransportFactory::class)
            ->setArguments([[], null, null, null]);
        // No Temporal connection → the pass does not touch the arguments.

        (new DurableTemporalTransportFactoryPass())->process($container);

        $args = $container->getDefinition(TemporalTransportFactory::class)->getArguments();
        $this->assertNull($args[2]);
        $this->assertNull($args[3]);
    }

    public function testPassInjectsWorkflowRegistryAtIndex3(): void
    {
        $container = $this->buildContainer();

        (new DurableTemporalTransportFactoryPass())->process($container);

        $args = $container->getDefinition(TemporalTransportFactory::class)->getArguments();

        $this->assertInstanceOf(Reference::class, $args[3], 'Argument [3] must be a DI Reference.');
        $this->assertSame(
            WorkflowRegistry::class,
            (string) $args[3],
            'Argument [3] must reference WorkflowRegistry, not EventStoreInterface or anything else.',
        );
    }

    public function testPassInjectsTemporalConnectionAtIndex2(): void
    {
        $container = $this->buildContainer();

        (new DurableTemporalTransportFactoryPass())->process($container);

        $args = $container->getDefinition(TemporalTransportFactory::class)->getArguments();

        $this->assertInstanceOf(Reference::class, $args[2]);
        $this->assertSame('durable.temporal.connection', (string) $args[2]);
    }

    public function testPassInjectsActivityWorkerAtIndex1WhenPresent(): void
    {
        $container = $this->buildContainer(withActivityWorker: true);

        (new DurableTemporalTransportFactoryPass())->process($container);

        $args = $container->getDefinition(TemporalTransportFactory::class)->getArguments();

        $this->assertInstanceOf(Reference::class, $args[1]);
        $this->assertSame('durable.temporal.activity_worker', (string) $args[1]);
    }

    public function testPassLeavesIndex1NullWhenActivityWorkerIsAbsent(): void
    {
        $container = $this->buildContainer(withActivityWorker: false);

        (new DurableTemporalTransportFactoryPass())->process($container);

        $args = $container->getDefinition(TemporalTransportFactory::class)->getArguments();

        $this->assertNull($args[1]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function buildContainer(bool $withActivityWorker = false): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $container->register(TemporalTransportFactory::class, TemporalTransportFactory::class)
            ->setArguments([[], null, null, null]);

        $container->register('durable.temporal.connection', \stdClass::class);
        $container->register(WorkflowRegistry::class, WorkflowRegistry::class);

        if ($withActivityWorker) {
            $container->register('durable.temporal.activity_worker', \stdClass::class);
        }

        return $container;
    }
}
