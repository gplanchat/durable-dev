<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\MagentoBench\Fixture\CallsNexus;
use Gplanchat\Durable\MagentoBench\Fixture\GreetThenWait;
use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableModule\Runtime\TableQueue\Queues;
use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\TestCase;

/**
 * #754: `resource/durable` assembles the Magento SQL backend, and a workflow runs end to end
 * through the assembled runtime. The journal lives on the `resource/durable` connection and the
 * shop's connection stays untouched. A second process runs the real `durable:worker` command (#736).
 */
final class TheDatabaseBackendRunsAWorkflowTest extends TestCase
{
    public function testAWorkflowWithAnActivityAndATimerRunsThroughTheAssembledRuntime(): void
    {
        $journal = JournalHarness::adapter();
        $objects = BenchRuntime::objectManager();
        $runtime = BenchRuntime::factory()->create();

        $result = $this->withAWorker(static fn() => $runtime->run(GreetThenWait::class, ['name' => 'Ada'], 'bench-greet'));

        self::assertSame('Hello, Ada, after one second', $result);
        $events = iterator_to_array($runtime->eventStore()->readStream(ExecutionId::fromString('bench-greet')), false);
        self::assertNotEmpty(array_filter($events, static fn(object $event): bool => $event instanceof TimerCompleted), 'the timer was fired by a FireWorkflowTimersMessage');
        self::assertGreaterThan(0, (int) $journal->fetchOne('SELECT COUNT(*) FROM durable_events WHERE execution_id = ?', ['bench-greet']), 'the events are in the journal database');
        self::assertSame([], $objects->get(ResourceConnection::class)->getConnection()->getTables('durable\_%'), "the shop's connection holds no Durable table");
    }

    public function testAWorkflowThatCallsANexusOperationFailsWithTheRefusal(): void
    {
        JournalHarness::adapter();
        $runtime = BenchRuntime::factory()->create();

        try {
            $this->withAWorker(static fn() => $runtime->run(CallsNexus::class, [], 'bench-nexus'));
            self::fail('the run was to fail');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(NexusUnsupportedByBackendException::class, $e->getMessage());
        }

        $failures = array_values(array_filter(
            iterator_to_array($runtime->eventStore()->readStream(ExecutionId::fromString('bench-nexus')), false),
            static fn(object $event): bool => $event instanceof WorkflowExecutionFailed,
        ));
        self::assertCount(1, $failures);
        self::assertSame(NexusUnsupportedByBackendException::class, $failures[0]->failureClass());
    }

    public function testADueTimerIsDispatchedAsAFireWorkflowTimersMessage(): void
    {
        JournalHarness::adapter();
        $backend = BenchRuntime::factory()->database();

        $backend->timers->dispatchTimerFire(ExecutionId::fromString('bench-timer'));

        $queued = $backend->queue->take(Queues::TIMER);
        self::assertNotNull($queued);
        self::assertSame('bench-timer', Queues::decode($queued->body, FireWorkflowTimersMessage::class)->executionId);
        self::assertNull($backend->queue->take(Queues::RESUME), 'no plain resume was sent for the timer');
    }

    public function testDiXmlSelectsTheDatabaseBackendFromResourceDurable(): void
    {
        $adapter = JournalHarness::adapter();

        $backend = BenchRuntime::objectManager()->get(RuntimeFactory::class)->database();

        self::assertNotSame(
            $backend->connection->fetchOne('SELECT DATABASE()'),
            BenchRuntime::objectManager()->get(ResourceConnection::class)->getConnection()->fetchOne('SELECT DATABASE()'),
        );
        self::assertSame($adapter->fetchOne('SELECT DATABASE()'), $backend->connection->fetchOne('SELECT DATABASE()'));
    }

    /** @param callable(): mixed $run */
    private function withAWorker(callable $run): mixed
    {
        $dir = sys_get_temp_dir() . '/durable-drain-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $worker = proc_open([\PHP_BINARY, __DIR__ . '/worker.php', '--time-limit=60'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', $dir . '/stderr', 'w']], $pipes);
        self::assertIsResource($worker);

        try {
            return $run();
        } finally {
            posix_kill(proc_get_status($worker)['pid'], \SIGTERM);
            proc_close($worker);
            $stderr = (string) file_get_contents($dir . '/stderr');
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
            self::assertSame('', $stderr, 'the worker process wrote to stderr');
        }
    }
}
