<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\MagentoBench\Fixture\GreetSlowly;
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

    public function testAnUnreadableBodyAndAnUnknownWorkflowAreAcknowledgedAndJournalled(): void
    {
        $this->backend->queue->enqueue(Queues::RESUME, 'not a serialized message');
        self::assertTrue($this->worker->tick([Queues::RESUME]));
        self::assertSame(0, $this->queued(), 'a body nobody can read is not delivered again');

        $id = ExecutionId::fromString('w-unknown');
        $this->backend->resumes->dispatchNewWorkflowRun($id, 'bench.no-such-workflow', []);
        self::assertTrue($this->worker->tick([Queues::RESUME]));

        self::assertSame(0, $this->queued());
        self::assertCount(1, $this->failuresOf($id), 'the run says why it stopped');
    }

    public function testAnIdleWorkerSaysSo(): void
    {
        self::assertFalse($this->worker->tick());
    }

    public function testAWorkerKilledMidActivityLeavesTheMessageToBeRedelivered(): void
    {
        $dir = sys_get_temp_dir() . '/durable-kill-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $id = ExecutionId::fromString('w-kill');
        $this->backend->resumes->dispatchNewWorkflowRun($id, GreetSlowly::class, ['name' => 'Ada']);

        $first = $this->spawn($dir, ['--time-limit=60']);

        try {
            self::assertTrue($this->waitFor(static fn(): bool => is_file($dir . '/started'), 20), 'the activity never started');
            $pid = proc_get_status($first)['pid'];
            posix_kill($pid, \SIGKILL);
        } finally {
            proc_close($first);
        }

        self::assertSame(1, $this->queued(Queues::ACTIVITY), 'the activity message is still queued: it was never acknowledged');
        self::assertSame(0, $this->claimsHeld(), 'the server freed the attempt claim with the killed connection');
        // The lease (600 s by default) is not waited for: it is brought to its end.
        $this->backend->connection->query('UPDATE durable_queue SET leased_until = NOW(3) - INTERVAL 1 SECOND');

        $second = $this->spawn($dir, ['--time-limit=60']);

        try {
            self::assertTrue($this->waitFor(fn(): bool => $this->completed($id), 30), 'the redelivered activity did not finish the run');
        } finally {
            posix_kill(proc_get_status($second)['pid'], \SIGTERM);
            proc_close($second);
        }

        $events = iterator_to_array($this->backend->eventStore->readStream($id), false);
        self::assertCount(1, array_filter($events, static fn(object $e): bool => $e instanceof ActivityCompleted), 'the activity is journalled once');
        self::assertCount(2, file($dir . '/runs'), 'it ran in two processes');
        $this->clean($dir);
    }

    public function testASignalStopsTheWorkerBetweenTwoMessages(): void
    {
        $dir = sys_get_temp_dir() . '/durable-stop-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $worker = $this->spawn($dir, ['--time-limit=60']);
        usleep(1_500_000);

        posix_kill(proc_get_status($worker)['pid'], \SIGTERM);
        $deadline = microtime(true) + 10;
        do {
            usleep(50_000);
            $status = proc_get_status($worker);
        } while ($status['running'] && microtime(true) < $deadline);

        self::assertFalse($status['running'], 'the worker is still running 10 s after SIGTERM');
        self::assertSame(0, $status['exitcode'], 'it left through its normal exit, not killed by the signal');
        self::assertStringContainsString('stopped', (string) file_get_contents($dir . '/stdout'));
        proc_close($worker);
        $this->clean($dir);
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

    private function completed(ExecutionId $id): bool
    {
        foreach ($this->backend->eventStore->readStream($id) as $event) {
            if ($event instanceof ExecutionCompleted) {
                return true;
            }
        }

        return false;
    }

    private function claimsHeld(): int
    {
        return (int) $this->backend->connection->fetchOne(
            'SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE object_type = ? AND object_name LIKE ?',
            ['USER LEVEL LOCK', 'durable\_resume\_%'],
        );
    }

    private function otherConnection(): \PDO
    {
        $config = JournalHarness::deploymentConfig()->get('db/connection/durable');
        [$host, $port] = explode(':', (string) $config['host']) + [1 => '3306'];

        return new \PDO(\sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, $config['dbname']), (string) $config['username'], (string) $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * @param list<string> $arguments
     *
     * @return resource
     */
    private function spawn(string $dir, array $arguments)
    {
        $process = proc_open(
            [\PHP_BINARY, __DIR__ . '/worker.php', ...$arguments],
            [1 => ['file', $dir . '/stdout', 'a'], 2 => ['file', $dir . '/stderr', 'a']],
            $pipes,
            null,
            ['DURABLE_BENCH_DIR' => $dir] + getenv(),
        );
        self::assertIsResource($process);

        return $process;
    }

    /** @param callable(): bool $condition */
    private function waitFor(callable $condition, int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                return false;
            }
            usleep(50_000);
        }

        return true;
    }

    private function clean(string $dir): void
    {
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
