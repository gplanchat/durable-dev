<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Gplanchat\Durable\Store\DbalChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\DbalEventStore;
use Gplanchat\Durable\Store\DbalWorkflowMetadataStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée les tables DBAL utilisées par durable (event store, métadonnées de reprise, lien parent↔enfant async).
 *
 * Idempotent : ignore les tables déjà présentes.
 *
 * Les noms par défaut correspondent à {@see config/packages/durable.yaml} de cette app exemple.
 */
#[AsCommand(
    name: 'durable:schema:init',
    description: 'Crée les tables DBAL pour journal, métadonnées workflow et lien parent-enfant (async)',
)]
final class DurableSchemaInitCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('event-table', null, InputOption::VALUE_OPTIONAL, 'Table journal événements', 'durable_events')
            ->addOption('metadata-table', null, InputOption::VALUE_OPTIONAL, 'Table métadonnées reprise workflow', 'durable_workflow_metadata')
            ->addOption('parent-link-table', null, InputOption::VALUE_OPTIONAL, 'Table lien parent↔enfant async', 'durable_child_workflow_parent_link')
            ->setHelp(
                <<<'HELP'
Les noms de tables doivent rester alignés avec <info>config/packages/durable.yaml</info>
(<comment>event_store.table_name</comment>, <comment>workflow_metadata.table_name</comment>,
<comment>child_workflow.parent_link_store.table_name</comment>).
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sm = $this->connection->createSchemaManager();

        $eventTable = (string) $input->getOption('event-table');
        $metadataTable = (string) $input->getOption('metadata-table');
        $parentLinkTable = (string) $input->getOption('parent-link-table');

        $this->ensureTable($io, $sm, $eventTable, fn () => (new DbalEventStore($this->connection, $eventTable))->createSchema());
        $this->ensureTable($io, $sm, $metadataTable, fn () => (new DbalWorkflowMetadataStore($this->connection, $metadataTable))->createSchema());
        $this->ensureTable($io, $sm, $parentLinkTable, fn () => (new DbalChildWorkflowParentLinkStore($this->connection, $parentLinkTable))->createSchema());

        $io->success('Schéma durable : tables prêtes (ou déjà existantes).');

        return Command::SUCCESS;
    }

    /**
     * @param callable(): void $create
     */
    private function ensureTable(SymfonyStyle $io, AbstractSchemaManager $sm, string $table, callable $create): void
    {
        if ($sm->tablesExist([$table])) {
            $io->note(\sprintf('Table <info>%s</info> déjà présente — ignorée.', $table));

            return;
        }

        $create();
        $io->writeln(\sprintf('Table <info>%s</info> créée.', $table));
    }
}
