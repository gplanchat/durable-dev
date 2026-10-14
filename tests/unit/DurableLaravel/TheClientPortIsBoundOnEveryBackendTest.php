<?php

declare(strict_types=1);

namespace unit\DurableLaravel;

use Gplanchat\Bridge\Temporal\Port\TemporalWorkflowClientPort;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Laravel\Queue\InProcessWorkflowResumeDispatcher;
use Gplanchat\Durable\Laravel\Queue\ResumeWorkflowJob;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Store\InMemoryWorkflowClient;
use Gplanchat\Durable\Testing\ConformanceClientWorkflow;
use Gplanchat\Durable\Testing\ConformanceClientWorkflowRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;
use unit\Bridge\SqlTestDatabase;
use unit\DurableLaravel\Fixtures\FakeQueue;
use unit\DurableLaravel\Fixtures\FakeQueueFactory;

/**
 * Task 4.2 of the workflow-repository change: the client port on the three backends the package
 * offers. The behaviour of the client is the conformance suite's; these tests check the wiring.
 */
final class TheClientPortIsBoundOnEveryBackendTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=durable-test'
        . '&journal_task_queue=durable-journal&activity_task_queue=durable-activities';

    public function testTheMemoryBackendRunsTheStartWhenTheQueueIsDrained(): void
    {
        $app = $this->app(['backend' => 'memory']);
        $port = $app->make(WorkflowClientPort::class);
        $id = ExecutionId::fromString('memory-1');

        $port->start($id, ConformanceClientWorkflow::TYPE, ['tag' => 'x']);
        // #881: a start only queues the run; the drain, or the next signal, runs it.
        $app->make(InProcessWorkflowResumeDispatcher::class)->drain();
        self::assertSame('waiting', $port->query($id, 'status'));
        $port->signal($id, 'pay');

        self::assertSame('paid', $port->query($id, 'status'));
    }

    public function testTheIlluminateBackendDeliversASignalOnTheQueue(): void
    {
        $queue = new FakeQueue();
        $app = $this->app(['backend' => 'illuminate'], $queue);
        $port = $app->make(WorkflowClientPort::class);
        $id = ExecutionId::fromString('queued-1');

        $port->start($id, ConformanceClientWorkflow::TYPE);
        $pushedByTheStart = \count($queue->pushed);
        $port->signal($id, 'pay');

        self::assertInstanceOf(InMemoryWorkflowClient::class, $port);
        self::assertGreaterThan($pushedByTheStart, \count($queue->pushed));
        self::assertContainsOnlyInstancesOf(ResumeWorkflowJob::class, array_column($queue->pushed, 'job'));
    }

    public function testTheIlluminateBackendDeliversAnUpdateWithItsIdOnTheQueue(): void
    {
        $queue = new FakeQueue();
        $app = $this->app(['backend' => 'illuminate'], $queue);
        $port = $app->make(WorkflowClientPort::class);
        $id = ExecutionId::fromString('queued-2');
        $port->start($id, ConformanceClientWorkflow::TYPE);

        try {
            $port->update($id, 'anything', ['a' => 1], 'update-7');
        } catch (\Gplanchat\Durable\Exception\DurableUpdateFailedException) {
            // No worker runs in this test: the update is on the queue and unanswered.
        }

        $updates = array_merge(...array_map(
            static fn(array $pushed): array => $pushed['job']->message->pendingUpdates,
            $queue->pushed,
        ));
        self::assertSame('update-7', $updates[0]['updateId'] ?? null);
    }

    public function testTheTemporalBackendBindsTheTemporalPort(): void
    {
        $app = $this->app(['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]]);

        self::assertInstanceOf(TemporalWorkflowClientPort::class, $app->make(WorkflowClientPort::class));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function backends(): iterable
    {
        yield 'memory' => [['backend' => 'memory']];
        yield 'illuminate' => [['backend' => 'illuminate']];
        yield 'temporal' => [['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]]];
    }

    /**
     * @param array<string, mixed> $durable
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testADeclaredRepositoryIsInjectedWithTheCataloguesAndThePort(array $durable): void
    {
        $repository = $this->app($durable)->make(ConformanceClientWorkflowRepository::class);

        self::assertInstanceOf(ConformanceClientWorkflowRepository::class, $repository);
    }

    /**
     * @param array<string, mixed> $durable
     */
    private function app(array $durable, ?FakeQueue $queue = null): Container
    {
        $app = new Container();
        $durable['workflows'] = [ConformanceClientWorkflow::class];
        $app->instance('config', new \ArrayObject(['durable' => $durable], \ArrayObject::ARRAY_AS_PROPS));
        if ('illuminate' === $durable['backend']) {
            $app->instance(Connection::class, SqlTestDatabase::illuminate());
            $app->instance(QueueFactory::class, new FakeQueueFactory($queue ?? new FakeQueue()));
        }
        (new DurableServiceProvider($app))->register();

        return $app;
    }
}
