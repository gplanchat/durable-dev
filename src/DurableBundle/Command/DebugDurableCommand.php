<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Command;

use Gplanchat\Durable\Workflow\WorkflowDescriber;
use Gplanchat\Durable\Workflow\WorkflowDescription;
use Gplanchat\Durable\Workflow\WorkflowHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The workflows the bundle registered and what each declares, read from its attributes: the entry
 * point with its parameters, then the signals, queries and updates by the name a caller sends.
 */
#[AsCommand(name: 'debug:durable', description: 'Lists the registered workflows, or shows the entry point and handlers of one.')]
final class DebugDurableCommand extends Command
{
    /**
     * @param list<class-string> $workflowClasses
     */
    public function __construct(private readonly array $workflowClasses)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('workflow', InputArgument::OPTIONAL, 'A workflow type (the #[AsWorkflow] name) or class');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $descriptions = array_map(WorkflowDescriber::describe(...), $this->workflowClasses);
        $wanted = $input->getArgument('workflow');

        if (null !== $wanted) {
            foreach ($descriptions as $description) {
                if ($wanted === $description->type || $wanted === $description->class) {
                    $this->show($io, $description);

                    return Command::SUCCESS;
                }
            }
            $io->error(\sprintf(
                'No workflow "%s" is registered. Registered types: %s.',
                OutputFormatter::escape($wanted),
                [] === $descriptions ? 'none' : implode(', ', array_map(static fn(WorkflowDescription $d): string => $d->type, $descriptions)),
            ));

            return Command::FAILURE;
        }

        if ([] === $descriptions) {
            $io->writeln('No workflow is registered. Put #[AsWorkflow] on a class in a service-registered namespace and clear the cache.');

            return Command::SUCCESS;
        }
        $io->table(['Type', 'Class', 'Entry point'], array_map(
            static fn(WorkflowDescription $d): array => [$d->type, $d->class, $d->entry->signature()],
            $descriptions,
        ));

        return Command::SUCCESS;
    }

    private function show(SymfonyStyle $io, WorkflowDescription $description): void
    {
        $io->title(\sprintf('%s (%s)', $description->type, $description->class));
        $io->writeln('Start:  ' . $description->entry->signature());
        foreach (['Signals' => $description->signals, 'Queries' => $description->queries, 'Updates' => $description->updates] as $title => $handlers) {
            $io->section($title);
            if ([] === $handlers) {
                $io->writeln('none');
                continue;
            }
            $io->table(['Name', 'Method'], array_map(
                static fn(string $name, WorkflowHandler $handler): array => [$name, $handler->signature()],
                array_keys($handlers),
                $handlers,
            ));
        }
    }
}
