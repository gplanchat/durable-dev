<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunProjection;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #851: what a run last waited on is about a run that is going on. The catalog already hides it
 * once the run has ended; the column must not keep it either, or a later reader of the table (a
 * query, an export, another catalog) reads a wait that is over.
 */
final class TheWaitIsClearedWhenTheRunEndsTest extends TestCase
{
    private Connection $connection;
    private DbalWorkflowRunProjection $projection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->projection = new DbalWorkflowRunProjection($this->connection, new DurableSchema($this->connection));
    }

    #[DataProvider('endings')]
    public function testTheColumnIsEmptyOnceTheRunHasEnded(WorkflowRunStatus $ending): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->projection->recordStart($id, 'App\\OrderWorkflow');
        $this->projection->recordWait($id, 'activity chargePayment attempt 1 in flight');

        $this->projection->recordOutcome($id, $ending);

        self::assertNull($this->connection->fetchOne('SELECT waiting_on FROM durable_workflow_runs WHERE execution_id = ?', ['exec-1']));
    }

    public function testAnEndingOnATableWithoutTheColumnIsNotAnError(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE durable_workflow_runs (execution_id VARCHAR(128) NOT NULL PRIMARY KEY, workflow_type VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL)');
        $projection = new DbalWorkflowRunProjection($connection, new DurableSchema($connection));
        $id = ExecutionId::fromString('exec-1');
        $projection->recordStart($id, 'App\\OrderWorkflow');

        $projection->recordOutcome($id, WorkflowRunStatus::Completed);

        self::assertSame('completed', $connection->fetchOne('SELECT status FROM durable_workflow_runs WHERE execution_id = ?', ['exec-1']));
    }

    public function testARunThatGoesOnKeepsItsWait(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->projection->recordStart($id, 'App\\OrderWorkflow');
        $this->projection->recordWait($id, 'timer due at 2026-10-03T10:00:00+00:00');

        self::assertSame('timer due at 2026-10-03T10:00:00+00:00', $this->connection->fetchOne('SELECT waiting_on FROM durable_workflow_runs WHERE execution_id = ?', ['exec-1']));
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
