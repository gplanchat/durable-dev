<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Command;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Failure\ActivityFailureEventFactory;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
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
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
        private readonly ActivityExecutor $activityExecutor,
        private readonly int $maxRetries = 0,
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
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

            $this->processMessage($message);
            ++$processed;

            if ($maxMessages > 0 && $processed >= $maxMessages) {
                break;
            }
        }

        $io->success(\sprintf('%d message(s) traité(s)', $processed));

        return Command::SUCCESS;
    }

    private function processMessage(ActivityMessage $message): void
    {
        try {
            $result = $this->activityExecutor->execute($message->activityName, $message->payload);
            $this->eventStore->append(new ActivityCompleted(
                $message->executionId,
                $message->activityId,
                $result,
            ));
            $this->resumeDispatcher->dispatchResume($message->executionId);
        } catch (\Throwable $e) {
            $options = ActivityOptions::fromMetadata($message->metadata);
            $maxAttempts = null !== $options && $options->maxAttempts > 0
                ? $options->maxAttempts
                : $this->maxRetries;

            $shouldRetry = $message->attempt() <= $maxAttempts
                && (null === $options || !$options->isNonRetryable($e));

            if ($shouldRetry) {
                $this->activityTransport->enqueue($message->withAttempt($message->attempt() + 1));
            } else {
                $this->eventStore->append(ActivityFailureEventFactory::fromActivityThrowable(
                    $message->executionId,
                    $message->activityId,
                    $message->activityName,
                    $message->attempt(),
                    $e,
                ));
                $this->resumeDispatcher->dispatchResume($message->executionId);
            }
        }
    }
}
