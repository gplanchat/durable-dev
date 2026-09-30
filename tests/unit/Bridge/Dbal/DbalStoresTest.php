<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalChildWorkflowParentLinkStore;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\TestCase;

/**
 * The DBAL backend replays from SQL what the in-memory backend replays from an array:
 * if the insertion order or the event type does not survive the round trip, every replay
 * diverges in silence. That is what this test guards.
 *
 * @see DUR030
 */
final class DbalStoresTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testJournalRoundTripsInInsertionOrder(): void
    {
        $store = new DbalEventStore($this->connection, $this->schema());

        // The table does not exist yet: the first append must create it.
        $store->append(new ActivityScheduled(ExecutionId::fromString('exec-1'), 'act-1', 'charge', ['amount' => 10]));
        $store->append(new SideEffectRecorded(ExecutionId::fromString('exec-1'), 'se-1', 'roll-42'));
        $store->append(new ActivityCompleted(ExecutionId::fromString('exec-1'), 'act-1', ['ok' => true]));
        $store->append(new ExecutionCompleted(ExecutionId::fromString('exec-1'), 'done'));

        $events = iterator_to_array($store->readStream(ExecutionId::fromString('exec-1')), false);

        self::assertCount(4, $events);
        self::assertInstanceOf(ActivityScheduled::class, $events[0]);
        self::assertInstanceOf(SideEffectRecorded::class, $events[1]);
        self::assertInstanceOf(ActivityCompleted::class, $events[2]);
        self::assertInstanceOf(ExecutionCompleted::class, $events[3]);

        self::assertSame('act-1', $events[0]->activityId());
        self::assertSame(['ok' => true], $events[2]->result());
    }

    public function testStreamsAndCountsAreScopedToOneExecution(): void
    {
        $store = new DbalEventStore($this->connection, $this->schema());

        $store->append(new ActivityScheduled(ExecutionId::fromString('exec-1'), 'act-1', 'charge', []));
        $store->append(new ActivityScheduled(ExecutionId::fromString('exec-2'), 'act-2', 'refund', []));
        $store->append(new ActivityScheduled(ExecutionId::fromString('exec-1'), 'act-3', 'ship', []));

        self::assertSame(2, $store->countEventsInStream(ExecutionId::fromString('exec-1')));
        self::assertSame(1, $store->countEventsInStream(ExecutionId::fromString('exec-2')));
        self::assertSame(0, $store->countEventsInStream(ExecutionId::fromString('exec-unknown')));
        self::assertSame([], iterator_to_array($store->readStream(ExecutionId::fromString('exec-unknown')), false));
    }

    public function testRecordedAtIsReadBackAsADate(): void
    {
        $store = new DbalEventStore($this->connection, $this->schema());
        $store->append(new ActivityScheduled(ExecutionId::fromString('exec-1'), 'act-1', 'charge', []));

        $entries = iterator_to_array($store->readStreamWithRecordedAt(ExecutionId::fromString('exec-1')), false);

        self::assertInstanceOf(\DateTimeImmutable::class, $entries[0]['recordedAt']);
    }

    public function testMetadataStoreLifecycle(): void
    {
        $store = new DbalWorkflowMetadataStore($this->connection, $this->schema());

        self::assertNull($store->get(ExecutionId::fromString('exec-1')));
        self::assertFalse($store->hasActiveWorkflowMetadata(ExecutionId::fromString('exec-1')));

        $store->save(ExecutionId::fromString('exec-1'), 'App\\Checkout', ['cart' => 7]);

        self::assertSame(
            ['workflowType' => 'App\\Checkout', 'payload' => ['cart' => 7], 'completed' => false],
            $store->get(ExecutionId::fromString('exec-1')),
        );
        self::assertTrue($store->hasActiveWorkflowMetadata(ExecutionId::fromString('exec-1')));

        // A second save (continue-as-new) overwrites the row instead of violating the primary key.
        $store->save(ExecutionId::fromString('exec-1'), 'App\\Checkout', ['cart' => 8]);
        self::assertSame(['cart' => 8], $store->get(ExecutionId::fromString('exec-1'))['payload']);

        $store->markCompleted(ExecutionId::fromString('exec-1'));
        self::assertFalse($store->hasActiveWorkflowMetadata(ExecutionId::fromString('exec-1')));
        // The type stays readable after completion (profiler, observability).
        self::assertSame('App\\Checkout', $store->get(ExecutionId::fromString('exec-1'))['workflowType']);

        $store->delete(ExecutionId::fromString('exec-1'));
        self::assertNull($store->get(ExecutionId::fromString('exec-1')));
    }

    public function testParentLinkStoreLifecycle(): void
    {
        $store = new DbalChildWorkflowParentLinkStore($this->connection, $this->schema());

        self::assertNull($store->getParentExecutionId(ExecutionId::fromString('child-1')));

        $store->link(ExecutionId::fromString('child-1'), ExecutionId::fromString('parent-1'));
        $store->link(ExecutionId::fromString('child-2'), ExecutionId::fromString('parent-1'));
        $store->link(ExecutionId::fromString('child-3'), ExecutionId::fromString('parent-2'));

        self::assertSame('parent-1', $store->getParentExecutionId(ExecutionId::fromString('child-1'))?->toString());

        $children = array_map(strval(...), $store->getChildExecutionIdsForParent(ExecutionId::fromString('parent-1')));
        sort($children);
        self::assertSame(['child-1', 'child-2'], $children);

        $store->unlink(ExecutionId::fromString('child-1'));
        self::assertNull($store->getParentExecutionId(ExecutionId::fromString('child-1')));
        self::assertEquals([ExecutionId::fromString('child-2')], $store->getChildExecutionIdsForParent(ExecutionId::fromString('parent-1')));
    }

    public function testSchemaCreationIsIdempotentAcrossStores(): void
    {
        $schema = $this->schema();
        $events = new DbalEventStore($this->connection, $schema);
        $metadata = new DbalWorkflowMetadataStore($this->connection, $schema);

        $events->append(new ActivityScheduled(ExecutionId::fromString('exec-1'), 'act-1', 'charge', []));
        $metadata->save(ExecutionId::fromString('exec-1'), 'App\\Checkout', []);

        // A second DurableSchema on the same connection must not retry the CREATE TABLE statements.
        $second = new DbalEventStore($this->connection, $this->schema());
        $second->append(new ActivityScheduled(ExecutionId::fromString('exec-1'), 'act-2', 'ship', []));

        self::assertSame(2, $events->countEventsInStream(ExecutionId::fromString('exec-1')));
    }

    private function schema(): DurableSchema
    {
        return new DurableSchema($this->connection);
    }
}
