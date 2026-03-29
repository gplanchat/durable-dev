<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Bundle;

use Gplanchat\Durable\Bundle\DurableBundle;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowUpdateHandler;
use Gplanchat\Durable\Bundle\Handler\WorkflowRunHandler;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use Gplanchat\Durable\Transport\WorkflowRunMessage;
use integration\Gplanchat\Durable\Bundle\Support\OrderWaitWorkflow;
use integration\Gplanchat\Durable\Bundle\Support\StockHoldWorkflow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Mode distribué + transport Messenger **sync** : pas de boucle infinie sur attente signal/update,
 * complétion après livraison des messages de contrôle.
 *
 * @internal
 */
#[CoversClass(WorkflowRunHandler::class)]
#[CoversClass(DeliverWorkflowSignalHandler::class)]
#[CoversClass(DeliverWorkflowUpdateHandler::class)]
final class DurableDistributedMessengerIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DurableDistributedTestKernel::class;
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        self::$class = null;
        self::$kernel = null;
        self::$booted = false;
        restore_exception_handler();
    }

    #[Test]
    public function workflowWaitsForSignalThenCompletesViaMessengerStack(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->get(\Gplanchat\Durable\WorkflowRegistry::class)->registerClass(OrderWaitWorkflow::class);

        $executionId = '01900000-0000-7000-8000-0000000000a1';
        $bus = $container->get(MessageBusInterface::class);
        $meta = $container->get(WorkflowMetadataStore::class);
        $store = $container->get(EventStoreInterface::class);

        $bus->dispatch(new WorkflowRunMessage($executionId, 'OrderWait', []));

        self::assertNotNull($meta->get($executionId), 'workflow suspendu : métadonnées conservées');
        self::assertNull($this->lastExecutionCompletedResult($store, $executionId));

        $bus->dispatch(new DeliverWorkflowSignalMessage($executionId, 'approved', ['ref' => 'PO-9']));

        self::assertNull($meta->get($executionId), 'workflow terminé : métadonnées supprimées');
        self::assertSame(['ref' => 'PO-9'], $this->lastExecutionCompletedResult($store, $executionId));
    }

    #[Test]
    public function workflowWaitsForUpdateThenCompletesViaMessengerStack(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->get(\Gplanchat\Durable\WorkflowRegistry::class)->registerClass(StockHoldWorkflow::class);

        $executionId = '01900000-0000-7000-8000-0000000000a2';
        $bus = $container->get(MessageBusInterface::class);
        $meta = $container->get(WorkflowMetadataStore::class);
        $store = $container->get(EventStoreInterface::class);

        $bus->dispatch(new WorkflowRunMessage($executionId, 'StockHold', []));

        self::assertNotNull($meta->get($executionId));

        $bus->dispatch(new DeliverWorkflowUpdateMessage($executionId, 'confirmQty', ['qty' => 4], ['ok' => true]));

        self::assertNull($meta->get($executionId));
        self::assertSame(['ok' => true], $this->lastExecutionCompletedResult($store, $executionId));
    }

    private function lastExecutionCompletedResult(EventStoreInterface $store, string $executionId): mixed
    {
        $last = null;
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof ExecutionCompleted) {
                $last = $event->result();
            }
        }

        return $last;
    }
}

final class DurableDistributedTestKernel extends \Symfony\Component\HttpKernel\Kernel
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
        $loader->load(__DIR__.'/config/durable_distributed_sync_messenger.php');
    }
}
