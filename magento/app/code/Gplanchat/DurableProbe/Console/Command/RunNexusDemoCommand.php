<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Console\Command;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableProbe\Workflow\OrderNexusWorkflow;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento durable:demo:nexus <order> <amount> REF=qty …`, and Magento calls the three others.
 *
 * **On the cluster, and not here.** `MagentoRuntime::run()` would execute the workflow in this
 * process, which is not what the demonstration shows: a Nexus operation is served by another
 * application, and the execution awaiting it has to outlive the command that started it.
 * `workflowClient()->startAsync()` hands it to the cluster; the bench's journal worker advances it,
 * and this command does nothing but wait for the result and print it.
 *
 * It therefore proves nothing on its own: with no `bin/magento durable:worker --role=journal` facing
 * it, the execution starts and stays there. That is true of the two other mockups too, and it is
 * `demo/run.sh` that counts the processes.
 */
/*
 * Not `final`: Magento generates an `Interceptor` extending every class its container instantiates,
 * to carry the plugins. A final class makes the container compilation fail.
 */
class RunNexusDemoCommand extends Command
{
    public function __construct(
        private readonly RuntimeFactory $runtimeFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('durable:demo:nexus')
            ->setDescription('Gets billed, stocked and shipped by three other applications, through Nexus')
            ->addArgument('order', InputArgument::REQUIRED, 'The order identifier: it is what makes the reservation idempotent')
            ->addArgument('amount', InputArgument::REQUIRED, 'The amount to bill, in cents')
            ->addArgument('lines', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'REFERENCE=quantity, one or more')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'An ISO 4217 code', 'EUR')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to wait', '120');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $order = (string) $input->getArgument('order');

        $lines = [];
        foreach ((array) $input->getArgument('lines') as $line) {
            if (!\is_string($line) || !str_contains($line, '=')) {
                $output->writeln(sprintf('<error>"%s" is not in the REFERENCE=quantity format.</error>', (string) $line));

                return Command::INVALID;
            }
            [$reference, $quantity] = explode('=', $line, 2);
            $lines[$reference] = (int) $quantity;
        }

        // The message for a missing DSN comes from the factory, and it names `app/etc/env.php`:
        // catching it here to rewrite it would only say it less well.
        $client = $this->runtimeFactory->workflowClient();

        $output->writeln(sprintf('  order %s: %s', $order, json_encode($lines, \JSON_THROW_ON_ERROR)));
        $startedAt = microtime(true);

        // The payload keys are the workflow's parameter **names**, not their positions:
        // `mapInputToArguments` matches by name, and a rename on one side only would hand `null`.
        $client->startAsync(
            OrderNexusWorkflow::class,
            [
                'order' => $order,
                'lines' => $lines,
                'amount' => (int) $input->getArgument('amount'),
                'currency' => (string) $input->getOption('currency'),
            ],
            $order,
        );

        $output->writeln('  started. Magento holds nothing open while the others work.');

        $seconds = max(1, (int) $input->getOption('timeout'));
        $result = $client->pollForCompletion($order, 500, $seconds * 2);

        $output->writeln(json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        // The duration line says what happened, and not what usually happens: a refused order comes
        // back in a tenth of a second, and announcing "including the charge" would make a fast
        // refusal read as an abnormally quick charge.
        $charged = \is_array($result) && null !== ($result['charge'] ?? null);
        $shipped = \is_array($result) && true === ($result['shipment']['shipped'] ?? null);
        $output->writeln(sprintf(
            '<info>%.1f s%s</info>',
            microtime(true) - $startedAt,
            match (true) {
                $shipped => ', including two operations fulfilled by workflows, on two different hosts.',
                $charged => ': charged, but nothing left the warehouse.',
                default => ': nothing was charged.',
            },
        ));

        return $shipped ? Command::SUCCESS : Command::FAILURE;
    }
}
