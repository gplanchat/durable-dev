<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowRunCatalog;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The same promise as on DBAL (#851): once a run has ended, its `waiting_on` column is empty.
 */
final class TheWaitIsClearedWhenTheRunEndsTest extends TestCase
{
    private Connection $connection;
    private IlluminateWorkflowRunCatalog $catalog;

    protected function setUp(): void
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->connection = $capsule->getConnection();
        $this->catalog = new IlluminateWorkflowRunCatalog($this->connection, new DurableSchema($this->connection));
    }

    #[DataProvider('endings')]
    public function testTheColumnIsEmptyOnceTheRunHasEnded(WorkflowRunStatus $ending): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->catalog->recordStart($id, 'App\\OrderWorkflow');
        $this->catalog->recordWait($id, 'activity chargePayment attempt 1 in flight');

        $this->catalog->recordOutcome($id, $ending);

        self::assertNull($this->connection->table('durable_workflow_runs')->where('execution_id', 'exec-1')->value('waiting_on'));
    }

    public function testAnEndingOnATableWithoutTheColumnIsNotAnError(): void
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection = $capsule->getConnection();
        $connection->statement('CREATE TABLE durable_workflow_runs (execution_id VARCHAR(128) NOT NULL PRIMARY KEY, workflow_type VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL)');
        $catalog = new IlluminateWorkflowRunCatalog($connection, new DurableSchema($connection));
        $id = ExecutionId::fromString('exec-1');
        $catalog->recordStart($id, 'App\\OrderWorkflow');

        $catalog->recordOutcome($id, WorkflowRunStatus::Completed);

        self::assertSame('completed', $connection->table('durable_workflow_runs')->where('execution_id', 'exec-1')->value('status'));
    }

    public function testARunThatGoesOnKeepsItsWait(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->catalog->recordStart($id, 'App\\OrderWorkflow');
        $this->catalog->recordWait($id, 'timer due at 2026-10-03T10:00:00+00:00');

        self::assertSame('timer due at 2026-10-03T10:00:00+00:00', $this->connection->table('durable_workflow_runs')->where('execution_id', 'exec-1')->value('waiting_on'));
    }

    /**
     * @return iterable<string, array{WorkflowRunStatus}>
     */
    public static function endings(): iterable
    {
        yield 'completed' => [WorkflowRunStatus::Completed];
        yield 'failed' => [WorkflowRunStatus::Failed];
        yield 'cancelled' => [WorkflowRunStatus::Cancelled];
        yield 'continued as new' => [WorkflowRunStatus::ContinuedAsNew];
    }
}
