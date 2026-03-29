<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Dbal;

use Doctrine\DBAL\DriverManager;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\Store\DbalEventStore;
use Gplanchat\Durable\Store\EventSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DbalEventStore::class)]
final class DbalEventStoreTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private DbalEventStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->store = new DbalEventStore($this->connection);
        $this->store->createSchema();
    }

    #[Test]
    public function appendAndReadStream(): void
    {
        $executionId = 'exec-123';
        $this->store->append(new ExecutionStarted($executionId));
        $this->store->append(new ActivityScheduled($executionId, 'act-1', 'echo', ['msg' => 'hello']));
        $this->store->append(new ActivityCompleted($executionId, 'act-1', 'echoed'));

        $events = iterator_to_array($this->store->readStream($executionId));
        self::assertCount(3, $events);
        self::assertInstanceOf(ExecutionStarted::class, $events[0]);
        self::assertInstanceOf(ActivityScheduled::class, $events[1]);
        self::assertInstanceOf(ActivityCompleted::class, $events[2]);
        self::assertSame('echoed', $events[2]->result());
    }

    #[Test]
    public function readStreamReturnsOnlyEventsForRequestedExecution(): void
    {
        $this->store->append(new ExecutionStarted('exec-a'));
        $this->store->append(new ActivityScheduled('exec-a', 'a1', 'x', []));
        $this->store->append(new ExecutionStarted('exec-b'));
        $this->store->append(new ActivityCompleted('exec-b', 'b1', 'only-b'));

        $forA = iterator_to_array($this->store->readStream('exec-a'));
        self::assertCount(2, $forA);
        self::assertInstanceOf(ExecutionStarted::class, $forA[0]);
        self::assertInstanceOf(ActivityScheduled::class, $forA[1]);

        $forB = iterator_to_array($this->store->readStream('exec-b'));
        self::assertCount(2, $forB);
        self::assertInstanceOf(ExecutionStarted::class, $forB[0]);
        self::assertInstanceOf(ActivityCompleted::class, $forB[1]);
        self::assertSame('only-b', $forB[1]->result());
    }

    #[Test]
    public function childWorkflowScheduledWithParentClosePolicyRoundTripsThroughPersistence(): void
    {
        $executionId = 'parent-db';
        $event = new ChildWorkflowScheduled(
            $executionId,
            'child-db-1',
            'SubFlow',
            ['sku' => 'z'],
            ParentClosePolicy::RequestCancel,
            'requested-child-id',
        );
        $this->store->append($event);

        $events = iterator_to_array($this->store->readStream($executionId));
        self::assertCount(1, $events);
        $read = $events[0];
        self::assertInstanceOf(ChildWorkflowScheduled::class, $read);
        self::assertSame(ParentClosePolicy::RequestCancel, $read->parentClosePolicy());
        self::assertSame('requested-child-id', $read->requestedWorkflowId());
        self::assertSame(['sku' => 'z'], $read->input());

        $roundTrip = EventSerializer::deserialize(EventSerializer::serialize($read));
        self::assertEquals($read->payload(), $roundTrip->payload());
    }

    #[Test]
    public function workflowCancellationRequestedRoundTripsThroughPersistence(): void
    {
        $executionId = 'child-cancel-stream';
        $event = new WorkflowCancellationRequested($executionId, 'parent_request_cancel', 'parent-db');
        $this->store->append($event);

        $events = iterator_to_array($this->store->readStream($executionId));
        self::assertCount(1, $events);
        $read = $events[0];
        self::assertInstanceOf(WorkflowCancellationRequested::class, $read);
        self::assertSame('parent_request_cancel', $read->reason());
        self::assertSame('parent-db', $read->sourceParentExecutionId());

        $roundTrip = EventSerializer::deserialize(EventSerializer::serialize($read));
        self::assertEquals($read->payload(), $roundTrip->payload());
    }
}
