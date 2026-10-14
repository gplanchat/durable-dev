<?php

declare(strict_types=1);

/*
 * The application side of the client port test, in its own process. Prints JSON.
 *
 *   php client.php reset                   create the schema, empty every durable table
 *   php client.php drive <execution id>    start, query, signal, query, result through the repository
 */

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\ExecutionId;
use integration\DurableModule\ClientPort\Fixture\PaidOrderRepository;

require __DIR__ . '/backend.php';

$backend = durable_client_backend();
if ('reset' === $argv[1]) {
    foreach ($backend->connection->getTables('durable\_%') as $table) {
        $backend->connection->truncateTable($table);
    }
    echo '{}';
    exit;
}

$id = ExecutionId::fromString($argv[2]);
$repository = new PaidOrderRepository($backend->catalog, $backend->workflowClient(null, Duration::seconds(60)));

$repository->create($id)->start(tag: 'ada');
$order = $repository->get($id);
$before = $order->status();
$order->pay();
$after = $order->status();
// The bound is a deadline for a worker that never answers, not an expectation on speed.
$result = $order->result(Duration::seconds(60));

echo json_encode([
    'before' => $before,
    'after' => $after,
    'result' => $result,
    'events' => array_map(static fn(object $event): string => $event::class, iterator_to_array($backend->events->readStream($id), false)),
], \JSON_THROW_ON_ERROR);
