<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Command;

use Gplanchat\Durable\Observation\RecordedDetails;
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
    description: 'Diagnose one executionId: workflow metadata, parent/child links and the event journal.',
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
            ->addArgument('executionId', InputArgument::REQUIRED, 'Execution identifier (workflow or child).')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many events at most to detail in the output.', '30')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write structured JSON to stdout.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $executionId = trim((string) $input->getArgument('executionId'));
        if ('' === $executionId) {
            $output->writeln('<error>executionId cannot be empty.</error>');

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
                    // The same barrier as the profiler: the command reads a production journal,
                    // and a payload that refuses encoding would bring down the very diagnosis
                    // one came for.
                    'payload' => RecordedDetails::storable($event->payload()),
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
            $output->writeln(json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Durable diagnosis — ' . $executionId);

        $io->section('Workflow metadata');
        if (null === $meta) {
            $io->warning('No row in the metadata store for this identifier (an inline run with no dispatch, or an unknown id).');
        } else {
            $io->horizontalTable(
                ['workflowType', 'completed', 'payload (excerpt)'],
                [[
                    $meta['workflowType'],
                    isset($meta['completed']) && true === $meta['completed'] ? 'yes' : 'no',
                    $this->truncateJson($meta['payload']),
                ]],
            );
        }

        $io->section('Parent / child links');
        $io->listing([
            'Parent, if this id is a child: ' . ($parentId ?? '(none)'),
            'Children recorded under this id as parent: ' . (0 === \count($childIds) ? '(none)' : implode(', ', $childIds)),
        ]);

        $io->section('Event journal');
        $io->text(\sprintf('Total events: %d', $totalEvents));
        if ([] !== $histogram) {
            ksort($histogram);
            $histRows = [];
            foreach ($histogram as $k => $v) {
                $histRows[] = [$k, (string) $v];
            }
            $io->table(['Type', 'Count'], $histRows);
        }
        if ($limit > 0 && [] !== $sample) {
            $io->text(\sprintf('First events (at most %d):', $limit));
            foreach ($sample as $i => $row) {
                $io->writeln(\sprintf(
                    '  %d. [%s] %s — %s',
                    $i + 1,
                    $row['recordedAt'] ?? '?',
                    $row['type'],
                    $this->truncateJson($row['payload']),
                ));
            }
        } elseif ($limit > 0 && 0 === $totalEvents) {
            $io->note('Empty stream for this executionId.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function truncateJson(array $payload): string
    {
        $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        if (\strlen($json) > 200) {
            return substr($json, 0, 197) . '…';
        }

        return $json;
    }

    private function shortClassName(string $class): string
    {
        $i = strrpos($class, '\\');

        return false === $i ? $class : substr($class, $i + 1);
    }
}
