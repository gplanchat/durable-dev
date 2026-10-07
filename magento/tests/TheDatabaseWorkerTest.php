<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\MagentoBench\Fixture\GreetThenWait;
use Gplanchat\DurableModule\Runtime\DatabaseBackend;
use Gplanchat\DurableModule\Runtime\DatabaseWorker;
use Gplanchat\DurableModule\Runtime\TableQueue\Queues;
use PHPUnit\Framework\TestCase;

/**
 * #736: `durable:worker` on the SQL backend, against a real MySQL. A message is acknowledged only
 * once it is handled; a transient failure leaves it in the queue.
 */
final class TheDatabaseWorkerTest extends TestCase
{
    private DatabaseBackend $backend;
    private DatabaseWorker $worker;

    protected function setUp(): void
    {
        JournalHarness::adapter();
        $this->backend = BenchRuntime::factory()->database();
        $this->worker = new DatabaseWorker($this->backend, null, 0.05);
    }

    public function testAHeldExecutionLockRequeuesTheMessage(): void
    {
        $id = ExecutionId::fromString('w-held');
        $this->backend->resumes->dispatchNewWorkflowRun($id, GreetThenWait::class, ['name' => 'Ada']);
        $other = BenchRuntime::factory()->database();
        self::assertTrue($other->lock->tryAcquire('w-held'));

        self::assertTrue($this->worker->tick([Queues::RESUME]));
        self::assertSame(1, $this->queued(Queues::RESUME), 'the turn is taken: the message waits');
        self::assertSame(0, $this->queued(Queues::ACTIVITY));

        $other->lock->release('w-held');
        usleep(100_000);
        self::assertTrue($this->worker->tick([Queues::RESUME]));
        self::assertSame(1, $this->queued(Queues::ACTIVITY));
    }

    public function testAnIdleWorkerSaysSo(): void
    {
        self::assertFalse($this->worker->tick());
    }

    private function queued(?string $queue = null): int
    {
        return (int) $this->backend->connection->fetchOne(
            'SELECT COUNT(*) FROM durable_queue' . (null === $queue ? '' : ' WHERE queue_name = ?'),
            null === $queue ? [] : [$queue],
        );
    }

}
