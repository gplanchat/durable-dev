<?php

declare(strict_types=1);

namespace App\Command;

use App\Durable\Workflow\OrderWorkflow;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Starts the caller of the other direction: the shop has an order billed.
 *
 * It exists only in the shop's `demo` profile — the one whose journal is the cluster. The `dev`
 * profile, which serves `stock` from a DBAL journal, cannot call a Nexus operation: an SQL journal
 * has no server to address the scheduling to, and refuses while saying so.
 */
#[AsCommand(
    name: 'durable:demo:bill',
    description: 'Have the business verify then charge an order, through Nexus',
)]
final class DemoNexusBillingCommand extends Command
{
    public function __construct(
        // Optional: with no Temporal DSN this service does not exist, and the command still has to
        // load — otherwise the test bench's container refuses to compile.
        private readonly ?WorkflowClientInterface $client = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('order', InputArgument::REQUIRED, 'The order identifier')
            ->addArgument('amount', InputArgument::REQUIRED, 'The amount in cents')
            ->addArgument('currency', InputArgument::OPTIONAL, 'An ISO 4217 code', 'EUR')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to wait', '120')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $this->client) {
            $io->error([
                'No workflow client: this profile has no Temporal DSN.',
                'A Nexus call leaves from a workflow, and a workflow needs the cluster to schedule it.',
            ]);

            return Command::FAILURE;
        }

        $order = (string) $input->getArgument('order');
        $startedAt = microtime(true);

        $this->client->startAsync(
            OrderWorkflow::TYPE,
            [
                'order' => $order,
                'amount' => (int) $input->getArgument('amount'),
                'currency' => (string) $input->getArgument('currency'),
            ],
            $order,
        );

        $io->comment(\sprintf('%s started — the shop holds nothing open while it waits.', $order));

        $seconds = max(1, (int) $input->getOption('timeout'));
        $result = $this->client->pollForCompletion($order, 500, $seconds * 2);

        $io->writeln(json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
        $io->success(\sprintf('%.1f s — including the charge, fulfilled by a workflow on the other side.', microtime(true) - $startedAt));

        return Command::SUCCESS;
    }
}
