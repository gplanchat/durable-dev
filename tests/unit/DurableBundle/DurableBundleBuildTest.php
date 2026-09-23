<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\ActivityHandlerPass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\NexusHandlerPass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RegisterDurableMiddlewarePass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\TemporalReceiversPass;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\WorkflowPass;
use Gplanchat\Durable\Bundle\DurableBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Checks that DurableBundle::build() registers every required compiler pass.
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

    public function testTemporalReceiversPassRunsAfterNexusHandlerPass(): void
    {
        // NexusHandlerPass decides whether durable_nexus exists; checking its name before that
        // would miss a messenger.yaml transport that collides with it.
        $passes = $this->registeredPassClasses();

        self::assertContains(TemporalReceiversPass::class, $passes);
        self::assertGreaterThan(
            array_search(NexusHandlerPass::class, $passes, true),
            array_search(TemporalReceiversPass::class, $passes, true),
        );
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
