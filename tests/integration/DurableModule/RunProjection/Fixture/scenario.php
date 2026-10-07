<?php

declare(strict_types=1);

/*
 * One scenario of the run projection on Magento's real MySQL adapter, in its own process so the
 * adapter never meets the stubs of the unit suite. Prints the row of `durable_workflow_runs` as
 * JSON. Usage: scenario.php <completed|failed|cancelled|continued_as_new|going_on|twice>
 */

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Gplanchat\DurableModule\Store\MagentoWorkflowRunProjection;

require __DIR__ . '/bootstrap.php';
$loader->addPsr4('Gplanchat\\Durable\\', $root . '/src/Durable/', true);

$connection = durable_test_connection();
(new JournalSchema($connection))->setup();
$connection->delete('durable_workflow_runs');

$schema = new JournalSchema($connection);
$projection = new MagentoWorkflowRunProjection($connection, $schema);
$id = ExecutionId::fromString('exec-1');
$scenario = $argv[1] ?? throw new RuntimeException('a scenario name is expected');

$projection->recordStart($id, 'App\\OrderWorkflow');
$projection->recordPickup($id);
$projection->recordWait($id, 'activity spike.ship attempt 1 in flight');

match ($scenario) {
    'going_on' => null,
    'twice' => (static function () use ($projection, $id, $connection): void {
        $connection->update('durable_workflow_runs', ['started_at' => '2020-01-01 00:00:00', 'picked_up_at' => '2020-01-01 00:00:01']);
        $projection->recordStart($id, 'App\\RenamedWorkflow');
        $projection->recordPickup($id);
    })(),
    default => $projection->recordOutcome($id, WorkflowRunStatus::from($scenario)),
};

echo json_encode($connection->fetchRow('SELECT * FROM durable_workflow_runs WHERE execution_id = ?', ['exec-1']), \JSON_THROW_ON_ERROR);
