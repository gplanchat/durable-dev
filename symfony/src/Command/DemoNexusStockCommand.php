<?php

declare(strict_types=1);

namespace App\Command;

use App\Durable\Workflow\ReserveStockWorkflow;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Starts the caller of the demonstration: the business asks the shop for stock.
 *
 * It does not speak to the shop. It starts a workflow in `demo-business`, and it is that workflow
 * which calls the Nexus operation; the only link between the two applications is the endpoint,
 * created by `bin/demo-nexus`, and the contract they share.
 */
#[AsCommand(
    name: 'durable:demo:nexus',
    description: 'Ask the shop to hold stock, through Nexus',
)]
final class DemoNexusStockCommand extends Command
{
    public function __construct(
        // Optional: with no Temporal DSN this service does not exist, and the command still has to
        // load; otherwise the test bench's container refuses to compile.
        private readonly ?WorkflowClientInterface $client = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('order', InputArgument::REQUIRED, 'The order identifier (it is what makes the reservation idempotent)')
            ->addArgument('lines', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'REFERENCE=quantity, one or more')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for the verdict', '60')
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
        $lines = [];
        foreach ((array) $input->getArgument('lines') as $line) {
            if (!\is_string($line) || !str_contains($line, '=')) {
                $io->error(\sprintf('"%s" is not in the REFERENCE=quantity format.', (string) $line));

                return Command::INVALID;
            }
            [$reference, $quantity] = explode('=', $line, 2);
            $lines[$reference] = (int) $quantity;
        }

        $io->comment(\sprintf('order %s: %s', $order, json_encode($lines, \JSON_THROW_ON_ERROR)));

        // The payload keys are the workflow's parameter names, not their positions:
        // `mapInputToArguments` matches by name, and a rename on one side only would hand `null`.
        $this->client->startAsync(
            ReserveStockWorkflow::TYPE,
            ['order' => $order, 'lines' => $lines],
            $order,
        );

        $seconds = max(1, (int) $input->getOption('timeout'));
        $verdict = $this->client->pollForCompletion($order, 500, $seconds * 2);

        $io->writeln(json_encode($verdict, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        if (\is_array($verdict) && true === ($verdict['reserved'] ?? null)) {
            $io->success('The shop held the stock.');

            return Command::SUCCESS;
        }

        $io->warning('The shop could not hold everything.');

        return Command::SUCCESS;
    }
}
