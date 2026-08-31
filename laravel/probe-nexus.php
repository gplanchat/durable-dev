<?php

declare(strict_types=1);

/**
 * `php probe-nexus.php` — ce que la maquette sert, sans grappe et sans worker.
 *
 * La question à laquelle elle répond est celle qui a fait exister cette maquette : **six lignes de
 * `config/durable.php` suffisent-elles à ce que le registre du cœur connaisse les deux opérations
 * de `livraison` ?** Le chemin éprouvé est config → `DeclaredNexusOperations` → registre →
 * gestionnaire, et il ne demande ni cluster, ni endpoint, ni processus en face : `dispatch()` est
 * la même méthode que le worker Nexus appelle quand une tâche arrive.
 *
 * Elle vit au banc et pas dans le paquet publié, comme les sondes `probe-*.php` du banc Magento :
 * une sonde éprouve une intégration, elle n'est pas une fonctionnalité.
 */

use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$registre = $app->make(NexusOperationRegistry::class);
$livraison = NexusService::named('livraison');
$echecs = [];

$verifier = static function (string $quoi, bool $vrai) use (&$echecs): void {
    echo($vrai ? "  ok   " : "  ÉCHEC") . ' ' . $quoi . "\n";

    if (!$vrai) {
        $echecs[] = $quoi;
    }
};

$verifier('le registre sert livraison/planifier', $registre->serves($livraison, NexusOperationName::named('planifier')));
$verifier('le registre sert livraison/expedier', $registre->serves($livraison, NexusOperationName::named('expedier')));

// Immédiate : le gestionnaire répond sur la tâche. Un panier vide est refusé, ce qui prouve que
// c'est bien **le code du gestionnaire** qui a répondu, et pas un enregistrement vide.
$plan = $registre->dispatch($livraison, NexusOperationName::named('planifier'), [
    'commande' => 'SONDE-1',
    'lignes' => [],
]);
$verifier('planifier répond sur la tâche', $plan->isImmediate);
$verifier('planifier refuse un panier vide', false === ($plan->result['planifiee'] ?? null));

$plein = $registre->dispatch($livraison, NexusOperationName::named('planifier'), [
    'commande' => 'SONDE-2',
    'lignes' => ['MUG_BLUE' => 2],
]);
$verifier('planifier rend un créneau et un transporteur', true === ($plein->result['planifiee'] ?? null)
    && '' !== ($plein->result['creneau'] ?? '')
    && '' !== ($plein->result['transporteur'] ?? ''));

// Différée : aucun gestionnaire n'est appelé, le registre nomme le workflow qui remplira.
$expedition = $registre->dispatch($livraison, NexusOperationName::named('expedier'), [
    'commande' => 'SONDE-3',
    'creneau' => '2026-01-01 09:00-12:00',
]);
$verifier('expedier est remplie par un workflow', !$expedition->isImmediate);
$verifier('et c\'est ExpedierWorkflow', 'ExpedierWorkflow' === $expedition->workflowType);
$verifier('la charge de l\'appelant devient son entrée', 'SONDE-3' === ($expedition->workflowInput['commande'] ?? null));

if ([] !== $echecs) {
    echo "\n" . \count($echecs) . " vérification(s) en échec.\n";

    exit(1);
}

echo "\nLa maquette sert livraison : deux opérations, deux formes, aucune grappe.\n";
