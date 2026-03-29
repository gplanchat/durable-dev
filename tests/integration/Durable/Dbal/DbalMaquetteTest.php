<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Dbal;

use Doctrine\DBAL\DriverManager;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\DbalEventStore;
use Gplanchat\Durable\Transport\DbalActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
#[CoversClass(DbalEventStore::class)]
#[CoversClass(DbalActivityTransport::class)]
#[CoversClass(ExecutionEngine::class)]
final class DbalMaquetteTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private DbalEventStore $eventStore;
    private DbalActivityTransport $activityTransport;
    private RegistryActivityExecutor $activityExecutor;
    private ExecutionRuntime $runtime;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->eventStore = new DbalEventStore($this->connection);
        $this->activityTransport = new DbalActivityTransport($this->connection);
        $this->eventStore->createSchema();
        $this->activityTransport->createSchema();

        $this->activityExecutor = new RegistryActivityExecutor();
        $this->runtime = new ExecutionRuntime(
            $this->eventStore,
            $this->activityTransport,
            $this->activityExecutor,
        );
    }

    #[Test]
    public function scheduleAndCompleteViaDbal(): void
    {
        $this->activityExecutor->register('echo', fn (array $p) => $p['msg'] ?? 'ok');

        $engine = new ExecutionEngine($this->eventStore, $this->runtime);
        $executionId = (string) Uuid::v7();

        $result = $engine->start($executionId, function (WorkflowEnvironment $env) {
            return $env->await($env->activity('echo', ['msg' => 'hello']));
        });

        self::assertSame('hello', $result);

        $events = iterator_to_array($this->eventStore->readStream($executionId));
        self::assertCount(4, $events);
        self::assertInstanceOf(ExecutionStarted::class, $events[0]);
        self::assertInstanceOf(ActivityScheduled::class, $events[1]);
        self::assertInstanceOf(ActivityCompleted::class, $events[2]);
        self::assertInstanceOf(ExecutionCompleted::class, $events[3]);
    }
}
