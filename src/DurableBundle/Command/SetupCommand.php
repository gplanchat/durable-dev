<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Command;

use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Durable's `messenger:setup-transports`: creates the missing tables on the configured connection.
 *
 * Needed when `dbal.auto_setup` is false and migrations do not create them, and on MySQL when the
 * first write happens inside a transaction, where auto-creation refuses.
 */
#[AsCommand(
    name: 'durable:setup',
    description: 'Create the missing Durable tables on the configured DBAL connection.',
)]
final class SetupCommand extends Command
{
    public function __construct(
        private readonly DurableSchema $schema,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->schema->setup();
        $output->writeln('Durable tables are in place.');

        return Command::SUCCESS;
    }
}
