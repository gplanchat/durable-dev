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
    description: 'Exécute le spike d’exécution Temporal native (DUR024) — une activité visible dans l’UI',
)]
final class DurableTemporalNativeSpikeCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'dsn',
            null,
            InputOption::VALUE_REQUIRED,
            'DSN temporal:// (sinon variable d’environnement DURABLE_DSN)',
        );
        $this->addOption(
            'workflow-id',
            null,
            InputOption::VALUE_REQUIRED,
            'Identifiant d’exécution Temporal (défaut: UUID)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dsn = $input->getOption('dsn')
            ?: getenv('DURABLE_DSN')
            ?: '';
        if ('' === trim((string) $dsn)) {
            $io->error('Définissez --dsn= ou la variable d’environnement DURABLE_DSN.');

            return Command::FAILURE;
        }
        if (!extension_loaded('grpc')) {
            $io->error('L’extension PHP grpc est requise.');

            return Command::FAILURE;
        }

        $workflowId = $input->getOption('workflow-id') ?: 'durable-native-spike-'.Uuid::v4()->toRfc4122();

        $conn = TemporalConnection::fromDsn((string) $dsn);
        $spike = NativeExecutionSpike::create($conn);

        $io->comment('Workflow id: '.$workflowId);
        $io->comment('Types: '.NativeExecutionSpike::WORKFLOW_TYPE.' / '.NativeExecutionSpike::ACTIVITY_TYPE);

        $runId = $spike->run($workflowId);
        $io->success('Terminé. run_id='.$runId.' — vérifiez l’historique dans Temporal UI (activités).');

        return Command::SUCCESS;
    }
}
