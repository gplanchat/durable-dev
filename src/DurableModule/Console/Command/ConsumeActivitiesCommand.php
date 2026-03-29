<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Console\Command;

use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Store\DbalEventStore;
use Gplanchat\Durable\Transport\DbalActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Gplanchat\DurableModule\Api\ExecutionBackendInterface;
use Gplanchat\DurableModule\Model\Dbal\SharedDoctrineConnection;
use Gplanchat\DurableModule\Model\Execution\ExecutionBackendResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Consomme la file d’activités {@see DbalActivityTransport} (mode DBAL uniquement).
 *
 * La reprise de workflow distribuée nécessite un {@see \Gplanchat\Durable\Port\WorkflowResumeDispatcher}
 * dédié (Messenger côté Symfony, ou futur transport DBAL) — ici {@see NullWorkflowResumeDispatcher}
 * tant que le module ne fournit pas d’implémentation Magento.
 */
class ConsumeActivitiesCommand extends Command
{
    public function __construct(
        private readonly ExecutionBackendResolver $executionBackendResolver,
        private readonly SharedDoctrineConnection $sharedDoctrineConnection,
        private readonly ActivityExecutor $activityExecutor,
        private readonly int $maxActivityRetries = 0,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('gplanchat:durable:activities:consume');
        $this->setDescription('Poll durable_activity_outbox and run activities (DBAL backend only)');
        $this->addOption('max-messages', 'm', InputOption::VALUE_REQUIRED, 'Max messages to process (0 = unlimited)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $backend = $this->executionBackendResolver->get();
        if (ExecutionBackendInterface::CODE_DBAL !== $backend->getCode()) {
            $output->writeln('<error>Activity consume CLI applies to DBAL backend only. Current backend: '.$backend->getCode().'</error>');

            return Command::FAILURE;
        }

        $maxMessages = (int) $input->getOption('max-messages');
        $connection = $this->sharedDoctrineConnection->get();
        $transport = new DbalActivityTransport($connection);
        $eventStore = new DbalEventStore($connection);
        $processor = new ActivityMessageProcessor(
            $eventStore,
            $transport,
            $this->activityExecutor,
            new NullWorkflowResumeDispatcher(),
            $this->maxActivityRetries,
        );

        $processed = 0;
        while (true) {
            $message = $transport->dequeue();
            if (null === $message) {
                break;
            }
            $processor->process($message);
            ++$processed;
            if ($maxMessages > 0 && $processed >= $maxMessages) {
                break;
            }
        }

        $output->writeln(\sprintf('<info>%d</info> message(s) processed.', $processed));

        return Command::SUCCESS;
    }
}
