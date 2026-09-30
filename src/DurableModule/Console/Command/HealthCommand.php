<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Console\Command;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Is the cluster answering, and does a worker poll each role's queue. Without the `activity` worker
 * an execution stops at its first activity and nothing fails: this exit code is what to alert on.
 */
/*
 * Not `final`: the container instantiates it, so it generates an `Interceptor` extending it.
 */
class HealthCommand extends Command
{
    public function __construct(
        private readonly RuntimeFactory $runtimeFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('durable:health')
            ->setDescription('Fails when the cluster does not answer or a worker role has stopped polling');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $health = $this->runtimeFactory->catalog()->checkHealth();
        if ($health->ephemeral) {
            $output->writeln('No Temporal DSN is configured: executions run in the process that starts them, and there is no worker to miss. Set durable/temporal/dsn in app/etc/env.php to run on a cluster.');

            return Command::SUCCESS;
        }
        if (!$health->reachable) {
            $output->writeln(\sprintf('<error>%s</error>', OutputFormatter::escape($health->message)));

            return Command::FAILURE;
        }
        $output->writeln($health->message);

        $since = new \DateTimeImmutable(\sprintf('-%d seconds', RuntimeFactory::WORKER_SILENCE_SECONDS));
        $healthy = true;
        foreach ($this->runtimeFactory->workers() as $role => $queue) {
            if ($queue->polledSince($since)) {
                $output->writeln(\sprintf('%s: %d poller(s) on %s', $role, $queue->pollers, $queue->taskQueue));
                continue;
            }
            $healthy = false;
            $output->writeln(null !== $queue->error
                ? \sprintf('<error>%s: could not ask the cluster who polls %s: %s</error>', $role, $queue->taskQueue, OutputFormatter::escape($queue->error))
                : \sprintf('<error>%s: no worker has polled %s in %ds. Start bin/magento durable:worker --role=%1$s.</error>', $role, $queue->taskQueue, RuntimeFactory::WORKER_SILENCE_SECONDS));
        }

        return $healthy ? Command::SUCCESS : Command::FAILURE;
    }
}
