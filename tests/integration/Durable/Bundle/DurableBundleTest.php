<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Bundle;

use Gplanchat\Durable\Bundle\DurableBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 */
#[CoversClass(DurableBundle::class)]
final class DurableBundleTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DurableTestKernel::class;
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        self::$class = null;
        self::$kernel = null;
        self::$booted = false;
        /*
         * Le kernel Symfony enregistre un handler d'exceptions ; PHPUnit 11 signale un test « risky »
         * si la pile ne revient pas à l'état capturé en début de test. On retire le handler ajouté.
         */
        restore_exception_handler();
    }

    #[Test]
    public function bundleLoads(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(\Gplanchat\Durable\Store\EventStoreInterface::class), 'EventStoreInterface');
        self::assertTrue($container->has(\Gplanchat\Durable\Transport\ActivityTransportInterface::class), 'ActivityTransportInterface');
        self::assertTrue($container->has(\Gplanchat\Durable\ExecutionEngine::class), 'ExecutionEngine');
        self::assertTrue($container->has(\Gplanchat\Durable\ActivityExecutor::class), 'ActivityExecutor');
        self::assertTrue($container->has(\Gplanchat\Durable\Port\WorkflowBackendInterface::class), 'WorkflowBackendInterface');
        self::assertTrue($container->has(\Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface::class), 'ParentChildWorkflowCoordinatorInterface');
        self::assertTrue($container->has(\Gplanchat\Durable\Query\WorkflowQueryRunner::class), 'WorkflowQueryRunner');
        self::assertTrue($container->has(\Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler::class), 'DeliverWorkflowSignalHandler');
        self::assertTrue($container->has(\Gplanchat\Durable\Bundle\Handler\DeliverWorkflowUpdateHandler::class), 'DeliverWorkflowUpdateHandler');
        self::assertTrue($container->has(\Gplanchat\Durable\Bundle\Handler\FireWorkflowTimersHandler::class), 'FireWorkflowTimersHandler');
        self::assertTrue($container->has('durable.child_workflow_parent_link_store'), 'durable.child_workflow_parent_link_store');
        self::assertInstanceOf(
            \Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface::class,
            $container->get('durable.child_workflow_parent_link_store'),
        );
    }
}

final class DurableTestKernel extends \Symfony\Component\HttpKernel\Kernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DurableBundle(),
        ];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
        $loader->load(__DIR__.'/config/durable_test.php');
    }
}
