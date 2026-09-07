<?php

declare(strict_types=1);

/*
 * Probe — the failure OST003 names, and which has to disappear.
 *
 * "A consumer that dies half way through an order. The order is charged, the stock is not, and the
 * operator learns of it from the customer. Restarting the consumer charges the card again."
 *
 * This probe starts the probe module's slow workflow under a given execution identifier. It is
 * killed during the reservation — the card is charged by then, the stock is not — then restarted
 * under the **same** identifier. What is measured next fits on one line:
 * `var/log/durable-charges.log` must contain **one** line, not two.
 *
 *   php probe-resume.php here    <identifier> <seconds>   runs in this process
 *   php probe-resume.php cluster <identifier> <seconds>   starts on the cluster
 *   php probe-resume.php await   <identifier> <tries>     waits for the result
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
        // The old path: the workflow runs in THIS process. Its activities go into the in-memory
        // transport and die with it — that is what §5.3 had measured.
        printf("%d runs %s right here (pause %ds)\n", getmypid(), $executionId, $pauseSeconds);
        printf("%d finishes -> %s\n", getmypid(), var_export(
            $factory->create()->run($workflow, $input, $executionId),
            true,
        ));
        break;

    case 'cluster':
        // Task 5's path: the execution is started **on the cluster** and carried by the workers.
        // That is the only way an activity becomes a task that somebody else can resume after a
        // death.
        $factory->workflowClient()->startAsync($workflow, $input, $executionId);
        printf("%s started on the cluster (pause %ds)\n", $executionId, $pauseSeconds);
        break;

    case 'await':
        printf("%s -> %s\n", $executionId, var_export(
            $factory->workflowClient()->pollForCompletion($executionId, 500, (int) ($argv[3] ?? 60)),
            true,
        ));
        break;

    default:
        fwrite(STDERR, "usage: php probe-resume.php here|cluster|await <identifier> <seconds>\n");
        exit(2);
}
