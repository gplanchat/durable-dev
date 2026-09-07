<?php

declare(strict_types=1);

/**
 * `php probe-nexus.php` — what the mockup serves, with no cluster and no worker.
 *
 * The question it answers is the one that made this mockup exist: **are six lines of
 * `config/durable.php` enough for the core registry to know both operations of `delivery`?** The
 * path exercised is config → `DeclaredNexusOperations` → registry → handler, and it needs no
 * cluster, no endpoint and no process on the other side: `dispatch()` is the same method the Nexus
 * worker calls when a task arrives.
 *
 * It lives in the bench and not in the published package, like the `probe-*.php` of the Magento
 * bench: a probe exercises an integration, it is not a feature.
 */

use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$registry = $app->make(NexusOperationRegistry::class);
$delivery = NexusService::named('delivery');
$failures = [];

$check = static function (string $what, bool $true) use (&$failures): void {
    echo($true ? "  ok   " : "  FAIL ") . ' ' . $what . "\n";

    if (!$true) {
        $failures[] = $what;
    }
};

$check('the registry serves delivery/schedule', $registry->serves($delivery, NexusOperationName::named('schedule')));
$check('the registry serves delivery/ship', $registry->serves($delivery, NexusOperationName::named('ship')));

// Immediate: the handler answers on the task. An empty basket is refused, which proves it is
// **the handler's code** that answered, and not an empty registration.
$plan = $registry->dispatch($delivery, NexusOperationName::named('schedule'), [
    'order' => 'PROBE-1',
    'lines' => [],
]);
$check('schedule answers on the task', $plan->isImmediate);
$check('schedule refuses an empty basket', false === ($plan->result['scheduled'] ?? null));

$full = $registry->dispatch($delivery, NexusOperationName::named('schedule'), [
    'order' => 'PROBE-2',
    'lines' => ['MUG_BLUE' => 2],
]);
$check('schedule returns a slot and a carrier', true === ($full->result['scheduled'] ?? null)
    && '' !== ($full->result['slot'] ?? '')
    && '' !== ($full->result['carrier'] ?? ''));

// Deferred: no handler is called, the registry names the workflow that will fulfil.
$shipment = $registry->dispatch($delivery, NexusOperationName::named('ship'), [
    'order' => 'PROBE-3',
    'slot' => '2026-01-01 09:00-12:00',
]);
$check('ship is fulfilled by a workflow', !$shipment->isImmediate);
$check('and it is ShipWorkflow', 'ShipWorkflow' === $shipment->workflowType);
$check("the caller's payload becomes its input", 'PROBE-3' === ($shipment->workflowInput['order'] ?? null));

if ([] !== $failures) {
    echo "\n" . \count($failures) . " check(s) failed.\n";

    exit(1);
}

echo "\nThe mockup serves delivery: two operations, two shapes, no cluster.\n";
