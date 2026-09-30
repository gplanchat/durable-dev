<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableModule;

use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use PHPUnit\Framework\TestCase;
use unit\DurableModule\Fixture\NexusBillingHandler;
use unit\DurableModule\Fixture\NexusChargeWorkflow;
use unit\DurableModule\Fixture\OrderActivities;

/**
 * A Magento module serves Nexus operations (#668): the handler is listed in di.xml's `nexusHandlers`,
 * its contract named by `#[AsNexusServiceHandler]` as on Symfony, and the workflows that fulfil an
 * operation come from `workflowClasses` by their `#[FulfilsNexusOperation]`.
 */
final class MagentoServesNexusTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=durable-test&nexus_task_queue=magento-nexus&tls=0';

    public function testAListedHandlerAnswersAndADeclaredWorkflowFulfilsTheRest(): void
    {
        $registry = (new RuntimeFactory(workflowClasses: [NexusChargeWorkflow::class], temporalDsn: self::DSN, nexusHandlers: [new NexusBillingHandler()]))->nexusRegistry();

        $verify = $registry->dispatch(NexusService::named('billing'), NexusOperationName::named('verify'), ['order' => 'ORD-1', 'amount' => 1200, 'currency' => 'USD']);
        self::assertSame(['accepted' => false, 'reason' => 'EUR only, on this module'], $verify->result);

        $charge = $registry->dispatch(NexusService::named('billing'), NexusOperationName::named('charge'), ['order' => 'ORD-1', 'amount' => 1200, 'currency' => 'EUR']);
        self::assertSame('test.magento.charge', $charge->workflowType);
    }

    public function testAHandlerWithoutTheAttributeIsRefusedByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(OrderActivities::class);
        $this->expectExceptionMessage('#[AsNexusServiceHandler');

        (new RuntimeFactory(temporalDsn: self::DSN, nexusHandlers: [new class implements OrderActivities {
            public function charge(string $orderId): string
            {
                return $orderId;
            }

            public function reserveStock(string $orderId): string
            {
                return $orderId;
            }

            public function notifyCustomer(string $receipt): string
            {
                return $receipt;
            }
        }]))->nexusRegistry();
    }

    public function testAnOperationNoWorkflowFulfilsIsRefused(): void
    {
        // #714's refusal, shared through the core: without the charge workflow, a caller of
        // billing/charge would wait on a result nothing produces.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"charge"');
        $this->expectExceptionMessage('served by nobody');

        (new RuntimeFactory(temporalDsn: self::DSN, nexusHandlers: [new NexusBillingHandler()]))->nexusRegistry();
    }

    public function testAMisspeltWorkflowClassNamesTheModuleArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('the workflowClasses argument of RuntimeFactory');

        (new RuntimeFactory(workflowClasses: ['Vendor\Module\Workflow\Missing'], temporalDsn: self::DSN, nexusHandlers: [new NexusBillingHandler()]))->nexusRegistry();
    }

    public function testAListedHandlerIsRefusedWithoutAClusterToRouteIt(): void
    {
        // The memory backend cannot route a Nexus call (DUR036): the refusal falls at startup.
        $this->expectException(NexusUnsupportedByBackendException::class);

        (new RuntimeFactory(workflowClasses: [NexusChargeWorkflow::class], nexusHandlers: [new NexusBillingHandler()]))->nexusRegistry();
    }

    public function testAModuleThatServesNothingNeedsNoCluster(): void
    {
        self::assertFalse((new RuntimeFactory())->nexusRegistry()->serves(NexusService::named('billing'), NexusOperationName::named('verify')));
    }

    public function testTheNexusWorkerNeedsACluster(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('durable/temporal/dsn');

        (new RuntimeFactory(nexusHandlers: [new NexusBillingHandler()]))->nexusWorker();
    }
}
