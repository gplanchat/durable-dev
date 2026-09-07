<?php

declare(strict_types=1);

namespace App\Command;

use App\Durable\DurableMessengerDrain;
use App\Durable\DurableSampleWorkflows;
use Gplanchat\Durable\Query\WorkflowQueryEvaluator;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\WorkflowRegistry;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'durable:sample',
    description: 'Run a sample workflow (light equivalents of the temporalio/samples-php samples)',
)]
final class RunDurableSampleCommand extends Command
{
    public function __construct(
        private readonly WorkflowRegistry $workflowRegistry,
        private readonly WorkflowResumeDispatcher $workflowResumeDispatcher,
        private readonly MessageBusInterface $messageBus,
        private readonly EventStoreInterface $eventStore,
        private readonly WorkflowMetadataStore $workflowMetadataStore,
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ContainerInterface $receiverLocator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'workflow',
                InputArgument::OPTIONAL,
                'A type registered in WorkflowRegistry',
                DurableSampleWorkflows::GREETING,
            )
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'First name (GreetingWorkflow)', 'World')
            ->addOption('first', null, InputOption::VALUE_REQUIRED, 'The first name (ParallelGreetingWorkflow)', 'Alice')
            ->addOption('second', null, InputOption::VALUE_REQUIRED, 'The second name (ParallelGreetingWorkflow)', 'Bob')
            ->addOption('text', null, InputOption::VALUE_REQUIRED, 'Text (Echo / ParentCallsEchoChild)', 'from-parent')
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Timer delay in seconds (TimerThenTickWorkflow)', '0.01')
            ->addOption(
                'pause-seconds',
                null,
                InputOption::VALUE_REQUIRED,
                'Durable pause in seconds before the children (ParallelChildEchoWorkflow; 0 by default)',
                '0',
            )
            ->addOption('execution-id', null, InputOption::VALUE_REQUIRED, 'Execution UUID (generated when absent)')
            ->addOption(
                'no-drain',
                null,
                InputOption::VALUE_NONE,
                'With Messenger: only dispatch (separate consumers: messenger:consume …)',
            )
            ->setHelp(
                <<<'HELP'
This project shows <info>gplanchat/durable</info> with <comment>Symfony Messenger</comment>: workflow resumes
and activities travel over the <info>durable_workflows</info> and <info>durable_activities</info>
transports (Messenger, see <comment>config/packages/messenger.yaml</comment>).
In dev the event journal can use <comment>Temporal</comment> (see <comment>.env.dev</comment>) with no SQL database for Durable.

Available workflows (see <info>App\Durable\DurableSampleWorkflows</info>):

  <info>GreetingWorkflow</info>              : like <comment>SimpleActivity</comment>
  <info>ParallelGreetingWorkflow</info>     : like <comment>AsyncActivity</comment> (two activities, <comment>all</comment>)
  <info>EchoChildWorkflow</info>             : a child, uppercasing through an activity
  <info>ParentCallsEchoChildWorkflow</info>  : like <comment>Child</comment>
  <info>ParallelChildEchoWorkflow</info>     : two <comment>EchoChildWorkflow</comment> children under <comment>all</comment>
  <info>TimerThenTickWorkflow</info>         : a short timer then an activity
  <info>SideEffectRandomIdWorkflow</info>   : a replayable <comment>sideEffect</comment>

Without <comment>--no-drain</comment>, this command drains the transports locally (the short equivalent of
<info>php bin/console messenger:consume durable_workflows durable_activities</info>).

HTTP demo + web profiler: <info>php -S localhost:8000 -t public</info> then open <info>/durable/profiler-demo</info>.

Upstream reference: https://github.com/temporalio/samples-php
HELP
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $workflowType = (string) $input->getArgument('workflow');
        if (!$this->workflowRegistry->has($workflowType)) {
            $io->error(\sprintf('Type inconnu : %s', $workflowType));
            $io->listing([
                DurableSampleWorkflows::GREETING,
                DurableSampleWorkflows::PARALLEL_GREETING,
                DurableSampleWorkflows::ECHO_CHILD,
                DurableSampleWorkflows::PARENT_CALLS_CHILD,
                DurableSampleWorkflows::PARALLEL_CHILD_ECHO,
                DurableSampleWorkflows::TIMER_THEN_TICK,
                DurableSampleWorkflows::SIDE_EFFECT_ID,
            ]);

            return Command::FAILURE;
        }

        $payload = $this->buildPayload($workflowType, $input);
        $executionId = (string) ($input->getOption('execution-id') ?: Uuid::v4());

        $this->workflowResumeDispatcher->dispatchNewWorkflowRun($executionId, $workflowType, $payload);

        if ($input->getOption('no-drain')) {
            $io->note('ResumeWorkflowMessage dispatched. Run for instance:');
            $io->text('  php bin/console messenger:consume durable_workflows durable_activities -vv');

            return Command::SUCCESS;
        }

        if (!DurableMessengerDrain::drainUntilWorkflowSettled(
            $this->eventStore,
            $this->workflowMetadataStore,
            $this->messageBus,
            $this->receiverLocator,
            $executionId,
        )) {
            $io->warning('Messenger drain: iteration limit reached, or the execution did not finish.');
        }
        $result = WorkflowQueryEvaluator::lastExecutionResult($this->eventStore, $executionId);
        if (null === $result) {
            $io->error('No ExecutionCompleted in the journal (a failure, or an incomplete drain).');

            return Command::FAILURE;
        }

        $io->success(\sprintf('Execution %s finished.', $executionId));
        $io->writeln($this->formatResult($result));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(string $workflowType, InputInterface $input): array
    {
        return match ($workflowType) {
            DurableSampleWorkflows::GREETING => [
                'name' => $input->getOption('name'),
            ],
            DurableSampleWorkflows::PARALLEL_GREETING => [
                'first' => $input->getOption('first'),
                'second' => $input->getOption('second'),
            ],
            DurableSampleWorkflows::ECHO_CHILD => [
                'text' => $input->getOption('text'),
            ],
            DurableSampleWorkflows::PARENT_CALLS_CHILD => [
                'text' => $input->getOption('text'),
            ],
            DurableSampleWorkflows::PARALLEL_CHILD_ECHO => [
                'first' => $input->getOption('first'),
                'second' => $input->getOption('second'),
                'pauseSeconds' => (float) $input->getOption('pause-seconds'),
            ],
            DurableSampleWorkflows::TIMER_THEN_TICK => [
                'seconds' => (float) $input->getOption('seconds'),
            ],
            default => [],
        };
    }

    private function formatResult(mixed $result): string
    {
        if (\is_array($result)) {
            return json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
        }

        if (\is_string($result) || is_numeric($result)) {
            return (string) $result;
        }

        return json_encode($result, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
    }
}
