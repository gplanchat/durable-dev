<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Command;

use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'durable:execution:diagnose',
    description: 'Diagnostic pour un executionId : métadonnées workflow, liens parent/enfant et journal d’événements.',
)]
final class DiagnoseExecutionCommand extends Command
{
    public function __construct(
        private readonly WorkflowMetadataStore $workflowMetadataStore,
        private readonly EventStoreInterface $eventStore,
        private readonly ChildWorkflowParentLinkStoreInterface $childWorkflowParentLinkStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('executionId', InputArgument::REQUIRED, 'Identifiant d’exécution (workflow ou enfant).')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Nombre max d’événements détaillés dans la sortie.', '30')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Sortie structurée JSON sur stdout.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $executionId = trim((string) $input->getArgument('executionId'));
        if ('' === $executionId) {
            $output->writeln('<error>executionId ne peut pas être vide.</error>');

            return Command::FAILURE;
        }

        $limit = max(0, (int) $input->getOption('limit'));
        $asJson = (bool) $input->getOption('json');

        $meta = $this->workflowMetadataStore->get($executionId);
        $parentId = $this->childWorkflowParentLinkStore->getParentExecutionId($executionId);
        $childIds = $this->childWorkflowParentLinkStore->getChildExecutionIdsForParent($executionId);

        $totalEvents = $this->eventStore->countEventsInStream($executionId);
        $histogram = [];
        $sample = [];
        foreach ($this->eventStore->readStreamWithRecordedAt($executionId) as $row) {
            $event = $row['event'];
            $short = $this->shortClassName($event::class);
            $histogram[$short] = ($histogram[$short] ?? 0) + 1;
            if (\count($sample) < $limit) {
                $recordedAt = $row['recordedAt'];
                $sample[] = [
                    'type' => $short,
                    'recordedAt' => $recordedAt?->format(\DateTimeInterface::ATOM),
                    'payload' => $event->payload(),
                ];
            }
        }

        $payload = [
            'executionId' => $executionId,
            'metadata' => $meta,
            'parentExecutionId' => $parentId,
            'childExecutionIds' => $childIds,
            'eventStream' => [
                'total' => $totalEvents,
                'histogramByType' => $histogram,
                'sample' => $sample,
                'sampleLimit' => $limit,
            ],
        ];

        if ($asJson) {
            $output->writeln(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Diagnostic Durable — '.$executionId);

        $io->section('Métadonnées workflow');
        if (null === $meta) {
            $io->warning('Aucune ligne dans le store de métadonnées pour cet identifiant (run inline sans dispatch, ou ID inconnu).');
        } else {
            $io->horizontalTable(
                ['workflowType', 'completed', 'payload (extrait)'],
                [[
                    $meta['workflowType'],
                    isset($meta['completed']) && true === $meta['completed'] ? 'oui' : 'non',
                    $this->truncateJson($meta['payload']),
                ]],
            );
        }

        $io->section('Lien parent / enfants');
        $io->listing([
            'Parent si cet ID est un enfant : '.($parentId ?? '(aucun)'),
            'Enfants enregistrés pour cet ID comme parent : '.(0 === \count($childIds) ? '(aucun)' : implode(', ', $childIds)),
        ]);

        $io->section('Journal d’événements');
        $io->text(sprintf('Nombre total d’événements : %d', $totalEvents));
        if ([] !== $histogram) {
            ksort($histogram);
            $histRows = [];
            foreach ($histogram as $k => $v) {
                $histRows[] = [$k, (string) $v];
            }
            $io->table(['Type', 'Nombre'], $histRows);
        }
        if ($limit > 0 && [] !== $sample) {
            $io->text(sprintf('Premiers événements (max %d) :', $limit));
            foreach ($sample as $i => $row) {
                $io->writeln(sprintf(
                    '  %d. [%s] %s — %s',
                    $i + 1,
                    $row['recordedAt'] ?? '?',
                    $row['type'],
                    $this->truncateJson($row['payload']),
                ));
            }
        } elseif ($limit > 0 && 0 === $totalEvents) {
            $io->note('Flux vide pour cet executionId.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function truncateJson(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (\strlen($json) > 200) {
            return substr($json, 0, 197).'…';
        }

        return $json;
    }

    private function shortClassName(string $class): string
    {
        $i = strrpos($class, '\\');

        return false === $i ? $class : substr($class, $i + 1);
    }
}
