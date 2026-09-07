<?php

declare(strict_types=1);

/*
 * Probe — one execution that holds every case the observation screen has to know how to show.
 *
 * The bench only had a happy path. A frieze validated on three activities that succeed proves
 * nothing of a failure, of a timer or of a child: this probe produces the six shapes in one go, so
 * that the detail page is judged on what it renders and not on what one imagines.
 *
 *   php probe-cases.php cluster <identifier>   starts on the cluster (workers required)
 *   php probe-cases.php here    <identifier>   runs in this process, in memory
 */

require __DIR__ . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$mode = $argv[1] ?? 'cluster';
$caseId = $argv[2] ?? 'CASE-' . date('His');

$factory = $om->get(\Gplanchat\DurableModule\Runtime\RuntimeFactory::class);
$workflow = \Gplanchat\DurableProbe\Workflow\EveryCaseWorkflow::class;

if ('here' === $mode) {
    printf("%s run right here -> %s\n", $caseId, var_export($factory->create()->run($workflow, ['caseId' => $caseId]), true));

    exit(0);
}

$factory->workflowClient()->startAsync($workflow, ['caseId' => $caseId], $caseId);
printf("%s started on the cluster — `bin/magento durable:worker` has to be running\n", $caseId);
