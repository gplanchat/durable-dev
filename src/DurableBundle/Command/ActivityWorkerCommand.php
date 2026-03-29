<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Command;

use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'durable:activity:consume',
    description: 'Consomme les messages d\'activité depuis la file',
)]
final class ActivityWorkerCommand extends Command
{
    public function __construct(
        private readonly ActivityMessageProcessor $activityMessageProcessor,
        private readonly ActivityTransportInterface $activityTransport,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max-messages', 'm', InputOption::VALUE_REQUIRED, 'Nombre max de messages à traiter', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maxMessages = (int) $input->getOption('max-messages');
        $processed = 0;

        while (true) {
            $message = $this->activityTransport->dequeue();
            if (null === $message) {
                break;
            }

            $this->activityMessageProcessor->process($message);
            ++$processed;

            if ($maxMessages > 0 && $processed >= $maxMessages) {
                break;
            }
        }

        $io->success(\sprintf('%d message(s) traité(s)', $processed));

        return Command::SUCCESS;
    }
}
