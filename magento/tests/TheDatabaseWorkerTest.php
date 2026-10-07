<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\MagentoBench\Fixture\GreetThenWait;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
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

    public function testALockWaitTimeoutWhileHandlingLeavesTheMessageInTheQueue(): void
    {
        $id = ExecutionId::fromString('w-lockwait');
        $this->backend->resumes->dispatchNewWorkflowRun($id, GreetThenWait::class, ['name' => 'Ada']);
        $this->backend->connection->query('SET SESSION lock_wait_timeout = 1');
        $other = $this->otherConnection();
        $other->exec('LOCK TABLES durable_workflow_metadata WRITE');

        try {
            self::assertTrue($this->worker->tick([Queues::RESUME]), 'the message was taken');
        } finally {
            $other->exec('UNLOCK TABLES');
        }

        self::assertSame(1, $this->queued(), 'a lock wait timeout is transient: the message is not acknowledged');
        usleep(100_000);
        self::assertTrue($this->worker->tick([Queues::RESUME]));
        self::assertSame(0, $this->queued(Queues::RESUME), 'the redelivered resume was handled and acknowledged');
        self::assertSame(1, $this->queued(Queues::ACTIVITY), 'and it scheduled the activity');
    }

    public function testAResumeThatArrivesBeforeItsOutcomeIsRequeuedNotFailed(): void
    {
        $id = ExecutionId::fromString('w-early');
        $this->backend->resumes->dispatchNewWorkflowRun($id, GreetThenWait::class, ['name' => 'Ada']);
        self::assertTrue($this->worker->tick([Queues::RESUME]));
        $this->backend->queue->enqueue(Queues::RESUME, Queues::encode(new ResumeWorkflowMessage('w-early', [], AwaitedFact::activity('not-journalled'))));

        self::assertTrue($this->worker->tick([Queues::RESUME]));

        self::assertSame(1, $this->queued(Queues::RESUME), 'the early resume waits in the queue');
        self::assertSame([], $this->failuresOf($id));
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

    /** @return list<WorkflowExecutionFailed> */
    private function failuresOf(ExecutionId $id): array
    {
        return array_values(array_filter(
            iterator_to_array($this->backend->eventStore->readStream($id), false),
            static fn(object $e): bool => $e instanceof WorkflowExecutionFailed,
        ));
    }

    private function otherConnection(): \PDO
    {
        $config = JournalHarness::deploymentConfig()->get('db/connection/durable');
        [$host, $port] = explode(':', (string) $config['host']) + [1 => '3306'];

        return new \PDO(\sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, $config['dbname']), (string) $config['username'], (string) $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

}
