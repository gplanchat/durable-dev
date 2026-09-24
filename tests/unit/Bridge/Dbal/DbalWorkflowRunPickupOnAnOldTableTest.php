<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunProjection;
use PHPUnit\Framework\TestCase;

/**
 * `durable_workflow_runs` tables created before #447 have no `picked_up_at` column, and nothing
 * alters them (DUR037). A worker on such a table must keep working; the list just cannot tell a run
 * waiting for a worker, and says nothing about it.
 */
final class DbalWorkflowRunPickupOnAnOldTableTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE durable_workflow_runs (execution_id VARCHAR(128) NOT NULL PRIMARY KEY, workflow_type VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL)');
    }

    public function testAWorkerStartedBeforeTheTableExistsSeesTheColumnOnceItDoes(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new DurableSchema($connection, autoSetup: false);

        self::assertFalse($schema->runsTableTracksPickup(), 'no table yet');

        // The migrations run after the worker booted.
        $connection->executeStatement('CREATE TABLE durable_workflow_runs (execution_id VARCHAR(128) NOT NULL PRIMARY KEY, workflow_type VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL, picked_up_at DATETIME DEFAULT NULL)');

        self::assertTrue($schema->runsTableTracksPickup(), 'a missing table is no answer to keep');
    }

    public function testAPickupOnATableWithoutTheColumnIsNotAnError(): void
    {
        $schema = new DurableSchema($this->connection);
        $projection = new DbalWorkflowRunProjection($this->connection, $schema);
        $projection->recordStart('exec-1', 'App\\OrderWorkflow');

        $projection->recordPickup('exec-1');

        $page = (new DbalWorkflowRunCatalog($this->connection, $schema))->listRuns();
        self::assertFalse($page->tellsWaitingForWorker);
        $runs = $page->runs;
        self::assertCount(1, $runs);
        self::assertNull($runs[0]->waitingForWorkerSince, 'a table that cannot tell leaves the fact absent');
    }
}
