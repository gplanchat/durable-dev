<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Store\TemporalReadThroughEventStore;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\TemporalRuntimeAssembly;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The Temporal graph, written once for the three hosts (#356): each object is built once per
 * assembly, they share one client and one history cursor, and the definition loader reaches both
 * the task runner and the workflow client.
 */
final class TemporalRuntimeAssemblyTest extends TestCase
{
    public function testEachObjectIsBuiltOnceAndTheyShareOneClientAndOneCursor(): void
    {
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $assembly = self::assembly($client);

        self::assertSame($assembly->historyCursor(), $assembly->historyCursor());
        self::assertSame($assembly->workflowClient(), $assembly->workflowClient());
        self::assertSame($assembly->workflowTaskProcessor(), $assembly->workflowTaskProcessor());
        self::assertSame($assembly->runCatalog(), $assembly->runCatalog());
        self::assertSame($assembly->activityRpc(), $assembly->activityRpc());

        self::assertInstanceOf(TemporalHistoryCursor::class, $assembly->historyCursor());
        self::assertInstanceOf(TemporalWorkflowRunCatalog::class, $assembly->runCatalog());
        self::assertSame($assembly->historyCursor(), self::read($assembly->runCatalog(), 'historyCursor'));
        self::assertSame($assembly->historyCursor(), self::read($assembly->workflowClient(), 'historyCursor'));
        self::assertSame($client, self::read($assembly->workflowTaskProcessor(), 'client'));
        self::assertSame($client, self::read($assembly->runCatalog(), 'client'));
    }

    public function testTheDefinitionLoaderReachesTheTaskRunnerAndTheWorkflowClient(): void
    {
        $loader = new WorkflowDefinitionLoader();
        $assembly = self::assembly($this->createMock(WorkflowServiceClientInterface::class), $loader);

        $runner = self::read($assembly->workflowTaskProcessor(), 'runner');
        self::assertSame($loader, self::read($runner, 'workflowDefinitionLoader'));
        self::assertSame($loader, self::read($assembly->workflowClient(), 'workflowDefinitionLoader'));
    }

    public function testTheReadThroughStoreWrapsTheLocalStoreItIsGiven(): void
    {
        $assembly = self::assembly($this->createMock(WorkflowServiceClientInterface::class));
        $local = new InMemoryEventStore();

        $store = $assembly->readThroughEventStore($local);

        self::assertInstanceOf(TemporalReadThroughEventStore::class, $store);
        self::assertSame($local, self::read($store, 'localStore'));
        self::assertSame($assembly->workflowClient(), self::read($store, 'workflowClient'));
    }

    private static function assembly(WorkflowServiceClientInterface $client, ?WorkflowDefinitionLoader $loader = null): TemporalRuntimeAssembly
    {
        return new TemporalRuntimeAssembly(
            $client,
            new TemporalConnection('127.0.0.1:7233', 'default'),
            new WorkflowRegistry(),
            $loader ?? new WorkflowDefinitionLoader(),
        );
    }

    private static function read(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))->getValue($object);
    }
}
