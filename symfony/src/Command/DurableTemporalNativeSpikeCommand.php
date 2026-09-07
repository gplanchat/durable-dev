<?php

declare(strict_types=1);

namespace App\Command;

use Gplanchat\Bridge\Temporal\Spike\NativeExecutionSpike;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the DUR024 reference spike (StartWorkflow → activity → complete) against a real Temporal server.
 *
 * Requires ext-grpc and `DURABLE_DSN` (temporal://…).
 */
#[AsCommand(
    name: 'durable:temporal:native-spike',
    description: 'Run the native Temporal execution spike (DUR024) — one activity visible in the UI',
)]
final class DurableTemporalNativeSpikeCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'dsn',
            null,
            InputOption::VALUE_REQUIRED,
            'temporal:// DSN (otherwise the DURABLE_DSN environment variable)',
        );
        $this->addOption(
            'workflow-id',
            null,
            InputOption::VALUE_REQUIRED,
            'Temporal execution id (default: UUID)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dsn = $input->getOption('dsn')
            ?: getenv('DURABLE_DSN')
            ?: '';
        if ('' === trim((string) $dsn)) {
            $io->error('Set --dsn= or the DURABLE_DSN environment variable.');

            return Command::FAILURE;
        }
        if (!extension_loaded('grpc')) {
            $io->error('The grpc PHP extension is required.');

            return Command::FAILURE;
        }

        $workflowId = $input->getOption('workflow-id') ?: 'durable-native-spike-'.Uuid::v4()->toRfc4122();

        $conn = TemporalConnection::fromDsn((string) $dsn);
        $spike = NativeExecutionSpike::create($conn);

        $io->comment('Workflow id: '.$workflowId);
        $io->comment('Types: '.NativeExecutionSpike::WORKFLOW_TYPE.' / '.NativeExecutionSpike::ACTIVITY_TYPE);

        $runId = $spike->run($workflowId);
        $io->success('Done. run_id='.$runId.' — check the history in the Temporal UI (activities).');

        return Command::SUCCESS;
    }
}
