<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\ActivityHandlerPass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\DurableTemporalTransportFactoryPass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RegisterWorkflowDispatchProfilerMiddlewarePass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\WorkflowPass;
use Gplanchat\Durable\Bundle\DurableBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

/**
 * Vérifie que DurableBundle::build() enregistre tous les compiler passes requis.
 *
 * Régression couverte : DurableTemporalTransportFactoryPass créé mais non enregistré,
 * causant un crash des workers durable_temporal_activity au démarrage (exit code 1).
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
     * Régression : DurableTemporalTransportFactoryPass était créé mais non enregistré.
     * Sans ce pass, TemporalTransportFactory ne reçoit pas TemporalActivityWorker et
     * les workers messenger:consume durable_temporal_activity crashent immédiatement.
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

    public function testRegisterWorkflowDispatchProfilerMiddlewarePassIsRegistered(): void
    {
        self::assertContains(
            RegisterWorkflowDispatchProfilerMiddlewarePass::class,
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
