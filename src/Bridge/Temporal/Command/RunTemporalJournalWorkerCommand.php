<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Command;

use Gplanchat\Bridge\Temporal\Messenger\TemporalJournalTransport;
use Gplanchat\Bridge\Temporal\Messenger\TemporalJournalTransportFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Long-running poll loop for the Temporal journal workflow (FrankenPHP Worker or systemd equivalent).
 * Same logic as {@see TemporalJournalTransport::get()} but without Symfony Messenger worker framing.
 */
#[AsCommand(
    name: 'durable:temporal:journal-worker:run',
    description: 'Poll Temporal workflow task queue and complete Durable journal workflow tasks (gRPC, no SDK)',
)]
final class RunTemporalJournalWorkerCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'dsn',
            null,
            InputOption::VALUE_REQUIRED,
            'DSN temporal-journal://host:port?namespace=default&task_queue=durable-journal',
        );
        $this->addOption(
            'max-ticks',
            null,
            InputOption::VALUE_OPTIONAL,
            'Stop after N poll iterations; omit for unlimited loop (FrankenPHP / systemd worker)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsn = $input->getOption('dsn');
        if (!\is_string($dsn) || '' === $dsn) {
            throw new \InvalidArgumentException('Option --dsn is required (temporal-journal://...).');
        }

        $io = new SymfonyStyle($input, $output);
        $settings = TemporalJournalTransportFactory::parseSettings($dsn);
        $transport = TemporalJournalTransport::fromSettings($settings);

        $io->info(\sprintf('Polling journal queue "%s" on %s (namespace %s)', $settings->taskQueue, $settings->target, $settings->namespace));

        $maxTicksRaw = $input->getOption('max-ticks');
        if (null === $maxTicksRaw || '' === $maxTicksRaw) {
            $this->runPollForever($transport);

            return Command::SUCCESS;
        }

        $maxTicks = (int) $maxTicksRaw;
        for ($i = 0; $i < $maxTicks; ++$i) {
            iterator_to_array($transport->get());
        }

        return Command::SUCCESS;
    }

    private function runPollForever(TemporalJournalTransport $transport): void
    {
        while (true) {
            iterator_to_array($transport->get());
        }
    }
}
