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
use PHPUnit\Framework\TestCase;

/**
 * Le backend DBAL rejoue depuis SQL ce que le backend in-memory rejoue depuis un tableau :
 * si l'ordre d'insertion ou le type d'événement ne survit pas à l'aller-retour, tout replay
 * diverge en silence. C'est ce que ce test garde.
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

        // La table n'existe pas encore : le premier append doit la créer.
        $store->append(new ActivityScheduled('exec-1', 'act-1', 'charge', ['amount' => 10]));
        $store->append(new SideEffectRecorded('exec-1', 'se-1', 'roll-42'));
        $store->append(new ActivityCompleted('exec-1', 'act-1', ['ok' => true]));
        $store->append(new ExecutionCompleted('exec-1', 'done'));

        $events = iterator_to_array($store->readStream('exec-1'), false);

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

        $store->append(new ActivityScheduled('exec-1', 'act-1', 'charge', []));
        $store->append(new ActivityScheduled('exec-2', 'act-2', 'refund', []));
        $store->append(new ActivityScheduled('exec-1', 'act-3', 'ship', []));

        self::assertSame(2, $store->countEventsInStream('exec-1'));
        self::assertSame(1, $store->countEventsInStream('exec-2'));
        self::assertSame(0, $store->countEventsInStream('exec-unknown'));
        self::assertSame([], iterator_to_array($store->readStream('exec-unknown'), false));
    }

    public function testRecordedAtIsReadBackAsADate(): void
    {
        $store = new DbalEventStore($this->connection, $this->schema());
        $store->append(new ActivityScheduled('exec-1', 'act-1', 'charge', []));

        $entries = iterator_to_array($store->readStreamWithRecordedAt('exec-1'), false);

        self::assertInstanceOf(\DateTimeImmutable::class, $entries[0]['recordedAt']);
    }

    public function testMetadataStoreLifecycle(): void
    {
        $store = new DbalWorkflowMetadataStore($this->connection, $this->schema());

        self::assertNull($store->get('exec-1'));
        self::assertFalse($store->hasActiveWorkflowMetadata('exec-1'));

        $store->save('exec-1', 'App\\Checkout', ['cart' => 7]);

        self::assertSame(
            ['workflowType' => 'App\\Checkout', 'payload' => ['cart' => 7], 'completed' => false],
            $store->get('exec-1'),
        );
        self::assertTrue($store->hasActiveWorkflowMetadata('exec-1'));

        // Un second save (continue-as-new) écrase la ligne au lieu de violer la clé primaire.
        $store->save('exec-1', 'App\\Checkout', ['cart' => 8]);
        self::assertSame(['cart' => 8], $store->get('exec-1')['payload']);

        $store->markCompleted('exec-1');
        self::assertFalse($store->hasActiveWorkflowMetadata('exec-1'));
        // Le type reste consultable après complétion (profiler, observabilité).
        self::assertSame('App\\Checkout', $store->get('exec-1')['workflowType']);

        $store->delete('exec-1');
        self::assertNull($store->get('exec-1'));
    }

    public function testParentLinkStoreLifecycle(): void
    {
        $store = new DbalChildWorkflowParentLinkStore($this->connection, $this->schema());

        self::assertNull($store->getParentExecutionId('child-1'));

        $store->link('child-1', 'parent-1');
        $store->link('child-2', 'parent-1');
        $store->link('child-3', 'parent-2');

        self::assertSame('parent-1', $store->getParentExecutionId('child-1'));

        $children = $store->getChildExecutionIdsForParent('parent-1');
        sort($children);
        self::assertSame(['child-1', 'child-2'], $children);

        $store->unlink('child-1');
        self::assertNull($store->getParentExecutionId('child-1'));
        self::assertSame(['child-2'], $store->getChildExecutionIdsForParent('parent-1'));
    }

    public function testSchemaCreationIsIdempotentAcrossStores(): void
    {
        $schema = $this->schema();
        $events = new DbalEventStore($this->connection, $schema);
        $metadata = new DbalWorkflowMetadataStore($this->connection, $schema);

        $events->append(new ActivityScheduled('exec-1', 'act-1', 'charge', []));
        $metadata->save('exec-1', 'App\\Checkout', []);

        // Un second DurableSchema sur la même connexion ne doit pas retenter les CREATE TABLE.
        $second = new DbalEventStore($this->connection, $this->schema());
        $second->append(new ActivityScheduled('exec-1', 'act-2', 'ship', []));

        self::assertSame(2, $events->countEventsInStream('exec-1'));
    }

    private function schema(): DurableSchema
    {
        return new DurableSchema($this->connection);
    }
}
