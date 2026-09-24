<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use PHPUnit\Framework\TestCase;
use unit\DurableModule\Fixture\OrderWorkflow;

/**
 * The factory is shared by the ObjectManager, so one factory is one request. Its Temporal objects
 * come from one assembly (#356): one gRPC client, not one per method, and the definition loader
 * reaches the task runner and the workflow client, which it used to miss.
 */
final class OneTemporalAssemblyPerFactoryTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=default';

    public function testEveryTemporalObjectOfAFactorySharesOneClient(): void
    {
        $factory = new RuntimeFactory(workflowClasses: [OrderWorkflow::class], temporalDsn: self::DSN);

        $client = self::read($factory->workflowClient(), 'client');
        self::assertSame($client, self::read($factory->catalog(), 'client'));
        self::assertSame($client, self::read($factory->journalWorker(), 'client'));
        self::assertSame($factory->workflowClient(), $factory->workflowClient());
    }

    public function testTheDefinitionLoaderReachesTheTaskRunnerAndTheWorkflowClient(): void
    {
        $factory = new RuntimeFactory(workflowClasses: [OrderWorkflow::class], temporalDsn: self::DSN);

        $runner = self::read($factory->journalWorker(), 'runner');
        self::assertNotNull(self::read($runner, 'workflowDefinitionLoader'));
        self::assertSame(self::read($runner, 'workflowDefinitionLoader'), self::read($factory->workflowClient(), 'workflowDefinitionLoader'));
    }

    private static function read(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))->getValue($object);
    }
}
