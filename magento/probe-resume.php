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
 *   php probe-resume.php <identifiant> <secondes de pause>
 */

require __DIR__ . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$executionId = $argv[1] ?? 'probe-resume';
$pauseSeconds = (int) ($argv[2] ?? 0);

$factory = $om->get(\Gplanchat\Durable\Magento\Runtime\RuntimeFactory::class);
$runtime = $factory->create();

printf("%d démarre %s (pause %ds)\n", getmypid(), $executionId, $pauseSeconds);

$result = $runtime->run(
    \Gplanchat\DurableProbe\Workflow\SlowOrderWorkflow::class,
    ['orderId' => 'ORD-' . $executionId, 'pauseSeconds' => $pauseSeconds],
    $executionId,
);

printf("%d termine %s -> %s\n", getmypid(), $executionId, var_export($result, true));
