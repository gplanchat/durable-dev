<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowRunCatalog;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The same promise as on DBAL: a `durable_workflow_runs` table created before #447 has no
 * `picked_up_at` column, a worker on it never fails, and the list leaves the fact absent.
 */
final class IlluminateWorkflowRunPickupOnAnOldTableTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->connection = $capsule->getConnection();
        $this->connection->statement('CREATE TABLE durable_workflow_runs (execution_id VARCHAR(128) NOT NULL PRIMARY KEY, workflow_type VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL)');
    }

    public function testAPickupOnATableWithoutTheColumnIsNotAnError(): void
    {
        $catalog = new IlluminateWorkflowRunCatalog($this->connection, new DurableSchema($this->connection));
        $catalog->recordStart('exec-1', 'App\\OrderWorkflow');

        $catalog->recordPickup('exec-1');

        $page = $catalog->listRuns();
        self::assertFalse($page->tellsWaitingForWorker);
        $runs = $page->runs;
        self::assertCount(1, $runs);
        self::assertNull($runs[0]->waitingForWorkerSince, 'a table that cannot tell leaves the fact absent');
    }

    public function testAWaitOnATableWithoutTheColumnIsNotAnError(): void
    {
        $catalog = new IlluminateWorkflowRunCatalog($this->connection, new DurableSchema($this->connection));
        $catalog->recordStart('exec-1', 'App\\OrderWorkflow');

        $catalog->recordWait('exec-1', 'activity charge attempt 2 in flight');

        self::assertNull($catalog->listRuns()->runs[0]->waitingOn, 'a table created before #324 leaves the fact absent');
    }
}
