<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\Bridge\Temporal\Port\TemporalWorkflowClientPort;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\DurableModule\Runtime\FactoryWorkflowClientPort;
use Gplanchat\DurableModule\Runtime\FactoryWorkflowRunCatalog;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use PHPUnit\Framework\TestCase;
use unit\DurableModule\Fixture\OrderRepository;
use unit\DurableModule\Fixture\OrderWorkflow;
use unit\DurableModule\Fixture\RecordingOrderActivities;

/**
 * A declared repository is injectable on Magento (#973, #976): `di.xml` prefers the two types its
 * constructor takes, and both lead to the backend the factory assembles.
 */
final class TheClientPortIsInjectableTest extends TestCase
{
    public function testDiXmlPrefersTheTwoTypesADeclaredRepositoryTakes(): void
    {
        $di = simplexml_load_file(__DIR__ . '/../../../src/DurableModule/etc/di.xml');
        self::assertNotFalse($di);

        foreach ([WorkflowClientPort::class => FactoryWorkflowClientPort::class, WorkflowRunCatalogInterface::class => FactoryWorkflowRunCatalog::class] as $type => $preferred) {
            $preference = $di->xpath(\sprintf('/config/preference[@for="%s"]', $type));
            self::assertSame($preferred, (string) ($preference[0]['type'] ?? ''), $type);
        }
    }

    public function testADeclaredRepositoryStartsAndFindsARunOnTheMemoryBackend(): void
    {
        $factory = new RuntimeFactory(workflowClasses: [OrderWorkflow::class], activityHandlers: [new RecordingOrderActivities()]);
        // What the ObjectManager does for the repository's constructor.
        $orders = new OrderRepository(new FactoryWorkflowRunCatalog($factory), new FactoryWorkflowClientPort($factory));

        $started = $orders->create(ExecutionId::fromString('order-1'))->start(orderId: 'A');

        self::assertSame('notify:charge:A', $started->result());
        self::assertSame('order-1', $orders->get(ExecutionId::fromString('order-1'))->executionId()->toString());
    }

    public function testTheTemporalBackendServesTheTemporalPort(): void
    {
        $factory = new RuntimeFactory(workflowClasses: [OrderWorkflow::class], temporalDsn: 'temporal://127.0.0.1:7233?namespace=default');

        self::assertInstanceOf(TemporalWorkflowClientPort::class, $factory->workflowClientPort());
        self::assertSame($factory->workflowClientPort(), $factory->workflowClientPort());
    }
}
