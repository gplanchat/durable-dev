<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Console\Command;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableProbe\Workflow\PlaceOrderWorkflow;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento durable:demo <orderId>` — the proof that the module runs.
 *
 * A Tier 1 bootstrap has no unit test proving that it starts: a Magento module
 * is not tested against anything smaller than Magento. This command is
 * therefore the harness, and it stands as an assertion — it exits with an error
 * if the workflow does not return what it must, and the journal it prints says
 * which of the three steps took place.
 *
 * What it does not prove yet: none of this goes through Magento's queue. The
 * backend is in memory, in this single process — so nothing survives the
 * command. It is slice 4 that puts the queue underneath, and slice 5 that puts
 * Temporal.
 */
/*
 * Not `final`: Magento generates an `Interceptor` extending every class its
 * container instantiates, to carry the plugins. A final class makes the
 * container compilation fail — "cannot extend final class" — and the message
 * does not say the keyword is to blame. The house writes `final` everywhere;
 * here the host forbids it, and saying so beats leaving it to be guessed.
 */
class RunDemoCommand extends Command
{
    public function __construct(
        private readonly RuntimeFactory $runtimeFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('durable:demo')
            ->setDescription('Runs a three-step order workflow inside Magento, on the in-memory backend')
            ->addArgument('order-id', InputArgument::OPTIONAL, 'The order identifier the workflow carries', 'ORD-4242');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $orderId = (string) $input->getArgument('order-id');

        // Not one activity written here any more: the factory registered them from the
        // `#[AsActivityMethod]` of the contracts its handlers implement, and the workflow from
        // `di.xml`'s list. The command now does nothing but start and report.
        $runtime = $this->runtimeFactory->create();
        $declared = $runtime->declaredActivities();

        foreach ($declared as $index => $activityName) {
            $output->writeln(sprintf('  %d. %s', $index + 1, $activityName));
        }

        $result = $runtime->run(PlaceOrderWorkflow::class, ['orderId' => $orderId]);
        $output->writeln(sprintf('  → %s', var_export($result, true)));

        if ('notify:charge:' . $orderId !== $result) {
            $output->writeln('<error>The three steps did not run in order.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>A workflow ran inside Magento, unmodified.</info>');

        return Command::SUCCESS;
    }
}
