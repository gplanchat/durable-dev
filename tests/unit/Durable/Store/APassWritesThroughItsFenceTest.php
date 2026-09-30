<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Exception\SupersededPassException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\FencedEventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\PassEventStore;
use Gplanchat\Durable\Store\ProjectingEventStore;
use PHPUnit\Framework\TestCase;

/**
 * DUR053: what a pass's writers are handed, and the decorator every journal backend wraps its
 * store in.
 */
final class APassWritesThroughItsFenceTest extends TestCase
{
    public function testAPassIsRefusedOnceANewerOneOpensOnTheSameExecution(): void
    {
        $store = new InMemoryEventStore();
        $older = PassEventStore::open($store, 'exec-1');
        PassEventStore::open($store, 'exec-1');

        $this->expectException(SupersededPassException::class);

        $older->append(new TimerCompleted(ExecutionId::fromString('exec-1'), 'timer-1'));
    }

    public function testAStoreThatCannotFenceIsHandedOverAsItIs(): void
    {
        $store = $this->createStub(EventStoreInterface::class);

        self::assertSame($store, PassEventStore::open($store, 'exec-1'));
    }

    public function testTheProjectingDecoratorForwardsTheFenceAndProjectsNothingOnARefusal(): void
    {
        $projection = new class implements WorkflowRunProjectionInterface {
            /** @var list<string> */
            public array $outcomes = [];

            public function recordStart(ExecutionId $executionId, string $workflowType): void {}

            public function recordOutcome(ExecutionId $executionId, WorkflowRunStatus $status): void
            {
                $this->outcomes[] = $executionId->toString();
            }
        };
        $store = new ProjectingEventStore(new InMemoryEventStore(), $projection);
        $older = $store->claimPass(ExecutionId::fromString('exec-1'));
        $store->claimPass(ExecutionId::fromString('exec-1'));

        try {
            $store->appendFenced(new ExecutionCompleted(ExecutionId::fromString('exec-1'), null), $older);
            self::fail('the older pass must be refused');
        } catch (SupersededPassException) {
        }

        self::assertSame([], $projection->outcomes);
    }

    public function testTheProjectingDecoratorOverAStoreThatCannotFenceAppendsPlainly(): void
    {
        // A store without the capability, as a third party may still ship one.
        $inner = new class implements EventStoreInterface {
            public int $appended = 0;

            public function append(\Gplanchat\Durable\Event\Event $event): void
            {
                ++$this->appended;
            }

            public function readStream(ExecutionId $executionId): iterable
            {
                return [];
            }

            public function readStreamWithRecordedAt(ExecutionId $executionId): iterable
            {
                return [];
            }

            public function countEventsInStream(ExecutionId $executionId): int
            {
                return $this->appended;
            }
        };
        $store = new ProjectingEventStore($inner, $this->createStub(WorkflowRunProjectionInterface::class));
        self::assertInstanceOf(FencedEventStoreInterface::class, $store);

        $fence = $store->claimPass(ExecutionId::fromString('exec-1'));
        $store->appendFenced(new TimerCompleted(ExecutionId::fromString('exec-1'), 'timer-1'), $fence);

        self::assertFalse($fence->fences());
        self::assertSame(1, $inner->appended);
    }
}
