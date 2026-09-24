<?php

declare(strict_types=1);

namespace unit\Bridge;

use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema as DbalSchema;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Bridge\Illuminate\Schema\DurableSchema as IlluminateSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateEventStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowRunCatalog;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use PHPUnit\Framework\TestCase;

/**
 * With `event_store.table_name: my_events`, the run page read `durable_events`: the catalogues
 * fell back to an event store on the default table when no history reader was injected, and the
 * bundle never injected one (#339, R-3).
 */
final class TheCatalogReadsTheConfiguredJournalTest extends TestCase
{
    public function testTheDbalCatalogReadsTheConfiguredEventsTable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new DbalSchema($connection, eventsTable: 'my_events');
        (new DbalEventStore($connection, $schema, 'my_events'))->append(new ExecutionStarted('exec-1', []));

        $history = (new DbalWorkflowRunCatalog($connection, $schema))->readHistory(self::describedRun());

        self::assertCount(1, $history);
    }

    public function testTheIlluminateCatalogReadsTheConfiguredEventsTable(): void
    {
        $connection = SqlTestDatabase::illuminate();
        $schema = new IlluminateSchema($connection, eventsTable: 'my_events');
        (new IlluminateEventStore($connection, $schema, 'my_events'))->append(new ExecutionStarted('exec-1', []));

        $history = (new IlluminateWorkflowRunCatalog($connection, $schema))->readHistory(self::describedRun());

        self::assertCount(1, $history);
    }

    private static function describedRun(): WorkflowRunDescription
    {
        return new WorkflowRunDescription('exec-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running);
    }
}
