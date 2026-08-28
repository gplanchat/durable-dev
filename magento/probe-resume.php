<?php

declare(strict_types=1);

/*
 * Sonde — la panne qu'OST003 nomme, et qui doit disparaître.
 *
 * « Un consommateur qui meurt au milieu d'une commande. La commande est encaissée, le stock ne
 * l'est pas, et l'exploitant l'apprend du client. Relancer le consommateur re-débite la carte. »
 *
 * Cette sonde lance le workflow lent du module de sonde sous un identifiant d'exécution donné. On
 * la tue pendant la réservation — la carte est alors débitée, le stock non — puis on la relance
 * sous le **même** identifiant. Ce qui se mesure ensuite tient en une ligne :
 * `var/log/durable-charges.log` doit contenir **une** ligne, pas deux.
 *
 *   php probe-resume.php here    <identifiant> <secondes>   exécute dans ce processus
 *   php probe-resume.php cluster <identifiant> <secondes>   lance sur la grappe
 *   php probe-resume.php await   <identifiant> <essais>     attend le résultat
 */

require __DIR__ . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$mode = $argv[1] ?? 'here';
$executionId = $argv[2] ?? 'probe-resume';
$pauseSeconds = (int) ($argv[3] ?? 0);

$factory = $om->get(\Gplanchat\DurableModule\Runtime\RuntimeFactory::class);
$workflow = \Gplanchat\DurableProbe\Workflow\SlowOrderWorkflow::class;
$input = ['orderId' => 'ORD-' . $executionId, 'pauseSeconds' => $pauseSeconds];

switch ($mode) {
    case 'here':
        // L'ancien chemin : le workflow tourne dans CE processus. Ses activités partent dans le
        // transport en mémoire et meurent avec lui — c'est ce que le §5.3 avait mesuré.
        printf("%d exécute %s ici même (pause %ds)\n", getmypid(), $executionId, $pauseSeconds);
        printf("%d termine -> %s\n", getmypid(), var_export(
            $factory->create()->run($workflow, $input, $executionId),
            true,
        ));
        break;

    case 'cluster':
        // Le chemin de la tâche 5 : l'exécution est lancée **sur la grappe** et menée par les
        // workers. C'est la seule façon qu'une activité devienne une tâche que quelqu'un d'autre
        // puisse reprendre après une mort.
        $factory->workflowClient()->startAsync($workflow, $input, $executionId);
        printf("%s démarré sur la grappe (pause %ds)\n", $executionId, $pauseSeconds);
        break;

    case 'await':
        printf("%s -> %s\n", $executionId, var_export(
            $factory->workflowClient()->pollForCompletion($executionId, 500, (int) ($argv[3] ?? 60)),
            true,
        ));
        break;

    default:
        fwrite(STDERR, "usage: php probe-resume.php here|cluster|await <identifiant> <secondes>\n");
        exit(2);
}
