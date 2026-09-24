<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\TemporalRuntimeAssembly;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * On Temporal the bundle takes the graph from the bridge's assembly (#356), like Laravel and
 * Magento: each service is one of the assembly's objects, under the id it always had, and the
 * definition loader reaches the assembly, hence the task runner and the workflow client.
 */
final class DurableTemporalAssemblyWiringTest extends TestCase
{
    public function testEachTemporalServiceIsBuiltByTheAssemblyUnderItsId(): void
    {
        $container = $this->load();

        foreach ([
            TemporalHistoryCursor::class => 'historyCursor',
            'durable.run_catalog.temporal' => 'runCatalog',
            WorkflowTaskRunner::class => 'workflowTaskRunner',
            WorkflowTaskProcessor::class => 'workflowTaskProcessor',
            WorkflowClient::class => 'workflowClient',
            WorkflowServiceActivityRpc::class => 'activityRpc',
            WorkflowServiceExecutionRpc::class => 'executionRpc',
            WorkflowServiceNexusRpc::class => 'nexusRpc',
            'durable.event_store.temporal' => 'readThroughEventStore',
        ] as $id => $method) {
            $factory = $container->getDefinition($id)->getFactory();
            self::assertIsArray($factory, $id);
            self::assertEquals([new Reference(TemporalRuntimeAssembly::class), $method], $factory, $id);
        }
    }

    public function testTheAssemblyGetsTheClientConnectionRegistryAndLoader(): void
    {
        $arguments = $this->load()->getDefinition(TemporalRuntimeAssembly::class)->getArguments();

        self::assertEquals([
            new Reference('durable.temporal.workflow_service_client'),
            new Reference('durable.temporal.connection'),
            new Reference(WorkflowRegistry::class),
            new Reference(WorkflowDefinitionLoader::class),
        ], $arguments);
    }

    private function load(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new DurableExtension())->load([['temporal' => ['dsn' => 'temporal://127.0.0.1:7233?namespace=durable-test']]], $container);

        return $container;
    }
}
