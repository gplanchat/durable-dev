<?php

declare(strict_types=1);

namespace unit\Bridge;

use Gplanchat\Bridge\Dbal\Schema\DurableSchema as DbalSchema;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Illuminate\Schema\DurableSchema as IlluminateSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateEventStore;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\ChildWorkflowRunner;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A child memo key Durable writes itself fails the parent before any child is scheduled, on the
 * in-memory, DBAL and Illuminate journals alike (#889). The Temporal case is in
 * {@see \unit\Gplanchat\Bridge\Temporal\Worker\TemporalChildWorkflowTest}.
 */
final class ReservedChildMemoKeyFailsTheRunTest extends TestCase
{
    /** @return iterable<string, array{\Closure(): EventStoreInterface, string}> */
    public static function backendsAndKeys(): iterable
    {
        $backends = [
            'in-memory' => static fn(): EventStoreInterface => new InMemoryEventStore(),
            'dbal' => static function (): EventStoreInterface {
                $connection = SqlTestDatabase::dbal();

                return new DbalEventStore($connection, new DbalSchema($connection));
            },
            'illuminate' => static function (): EventStoreInterface {
                $connection = SqlTestDatabase::illuminate();

                return new IlluminateEventStore($connection, new IlluminateSchema($connection));
            },
        ];
        foreach ($backends as $backend => $store) {
            foreach ([ChildWorkflowOptions::MEMO_KEY_DURABLE_EXECUTION_ID, ChildWorkflowOptions::MEMO_KEY_DURABLE_WAITING_ON] as $key) {
                yield $backend . ', ' . $key => [$store, $key];
            }
        }
    }

    /** @param \Closure(): EventStoreInterface $store */
    #[DataProvider('backendsAndKeys')]
    public function testAReservedChildMemoKeyFailsTheParentWithoutSchedulingTheChild(\Closure $store, string $key): void
    {
        $eventStore = $store();
        $executor = new RegistryActivityExecutor();
        $registry = new WorkflowRegistry();
        $registry->registerClass(ReservedMemoChild::class);
        $runtime = new ExecutionRuntime($eventStore, new InMemoryActivityTransport(), $executor, 0, null, true);
        $engine = new ExecutionEngine($eventStore, $runtime, new ChildWorkflowRunner($eventStore, $runtime, $registry, $executor, 0, false));

        try {
            $engine->start(ExecutionId::fromString('parent-889'), static fn(WorkflowEnvironment $env): string
                => $env->await($env->childWorkflowStub(ReservedMemoChild::class, new ChildWorkflowOptions(memo: [$key => 'x']))->run()));
            self::fail(\sprintf('the memo key "%s" must fail the run', $key));
        } catch (UnsupportedByBackendException $refusal) {
            self::assertStringContainsString(\sprintf('The key "%s" in ChildWorkflowOptions::$memo is reserved', $key), $refusal->getMessage());
        }

        foreach ($eventStore->readStream(ExecutionId::fromString('parent-889')) as $event) {
            self::assertNotInstanceOf(ChildWorkflowScheduled::class, $event);
        }
    }
}

#[AsWorkflow(name: 'ReservedMemoChild')]
final class ReservedMemoChild
{
    #[AsWorkflowMethod]
    public function run(): string
    {
        return 'child-result';
    }
}
