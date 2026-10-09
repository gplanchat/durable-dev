<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Console\Command;

use Gplanchat\DurableModule\Runtime\JournalConnectionResolver;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates the Durable tables on the journal's connection, and adds the columns a newer version
 * brings. Idempotent: a second run changes nothing.
 */
/*
 * Not `final`: the container instantiates it, so it generates an `Interceptor` extending it.
 */
class SetupCommand extends Command
{
    public function __construct(
        private readonly JournalConnectionResolver $journal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('durable:setup')
            ->setDescription('Creates the Durable tables on the resource/durable connection, and adds the missing columns');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $done = (new JournalSchema($this->journal->resolve()))->setup();
        } catch (\Throwable $e) {
            $output->writeln(\sprintf('<error>%s</error>', OutputFormatter::escape($e->getMessage())));

            return Command::FAILURE;
        }

        $output->writeln([] === $done ? 'The Durable tables are up to date.' : $done);

        return Command::SUCCESS;
    }
}
