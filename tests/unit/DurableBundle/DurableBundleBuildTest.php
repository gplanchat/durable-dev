<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\ActivityHandlerPass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\DurableTemporalTransportFactoryPass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\NexusHandlerPass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RegisterDurableMiddlewarePass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\WorkflowPass;
use Gplanchat\Durable\Bundle\DurableBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Checks that DurableBundle::build() registers every required compiler pass.
 *
 * Regression covered: DurableTemporalTransportFactoryPass created but not registered,
 * crashing the durable_temporal_activity workers at startup (exit code 1).
 *
 * @internal
 */
#[CoversClass(DurableBundle::class)]
final class DurableBundleBuildTest extends TestCase
{
    /**
     * @return array<int, class-string<CompilerPassInterface>>
     */
    private function registeredPassClasses(): array
    {
        $container = new ContainerBuilder();
        (new DurableBundle())->build($container);

        $passConfig = $container->getCompilerPassConfig();

        $all = array_merge(
            $passConfig->getBeforeOptimizationPasses(),
            $passConfig->getOptimizationPasses(),
            $passConfig->getBeforeRemovingPasses(),
            $passConfig->getRemovingPasses(),
            $passConfig->getAfterRemovingPasses(),
        );

        return array_map('get_class', $all);
    }

    /**
     * Regression: DurableTemporalTransportFactoryPass was created but not registered.
     * Without that pass, TemporalTransportFactory does not receive TemporalActivityWorker and
     * the messenger:consume durable_temporal_activity workers crash immediately.
     */
    public function testDurableTemporalTransportFactoryPassIsRegistered(): void
    {
        self::assertContains(
            DurableTemporalTransportFactoryPass::class,
            $this->registeredPassClasses(),
            'DurableTemporalTransportFactoryPass must be registered in DurableBundle::build(). '
            . 'Its absence causes TemporalActivityWorker to not be injected in TemporalTransportFactory, '
            . 'crashing the durable_temporal_activity Messenger workers.',
        );
    }

    public function testWorkflowPassIsRegistered(): void
    {
        self::assertContains(WorkflowPass::class, $this->registeredPassClasses());
    }

    public function testActivityHandlerPassIsRegistered(): void
    {
        self::assertContains(ActivityHandlerPass::class, $this->registeredPassClasses());
    }

    public function testNexusHandlerPassIsRegistered(): void
    {
        // Without it, an #[AsNexusOperationHandler] lays down its tag and nothing reads it: the
        // handler is never registered, and the worker polls a queue where nobody serves the
        // operation. That silence is exactly what §5.3 sets out to make impossible.
        self::assertContains(NexusHandlerPass::class, $this->registeredPassClasses());
    }

    public function testRegisterDurableMiddlewarePassIsRegistered(): void
    {
        self::assertContains(
            RegisterDurableMiddlewarePass::class,
            $this->registeredPassClasses(),
        );
    }

    public function testBuildDoesNotThrowWithFreshContainer(): void
    {
        $container = new ContainerBuilder();
        (new DurableBundle())->build($container);
        self::assertTrue(true);
    }
}
