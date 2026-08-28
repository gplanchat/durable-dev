<?php

declare(strict_types=1);

/*
 * Sonde — une exécution qui contient tous les cas que l'écran d'observation doit savoir montrer.
 *
 * Le banc n'avait qu'un chemin heureux. Une frise validée sur trois activités qui réussissent ne
 * prouve rien d'un échec, d'un minuteur ni d'un enfant : cette sonde produit les six formes d'un
 * coup, pour que la page de détail soit jugée sur ce qu'elle rend et non sur ce qu'on imagine.
 *
 *   php probe-cases.php cluster <identifiant>   lance sur la grappe (workers requis)
 *   php probe-cases.php here    <identifiant>   exécute dans ce processus, en mémoire
 */

require __DIR__ . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$mode = $argv[1] ?? 'cluster';
$caseId = $argv[2] ?? 'CASE-' . date('His');

$factory = $om->get(\Gplanchat\DurableModule\Runtime\RuntimeFactory::class);
$workflow = \Gplanchat\DurableProbe\Workflow\EveryCaseWorkflow::class;

if ('here' === $mode) {
    printf("%s exécuté ici même -> %s\n", $caseId, var_export($factory->create()->run($workflow, ['caseId' => $caseId]), true));

    exit(0);
}

$factory->workflowClient()->startAsync($workflow, ['caseId' => $caseId], $caseId);
printf("%s démarré sur la grappe — `bin/magento durable:worker` doit tourner\n", $caseId);
