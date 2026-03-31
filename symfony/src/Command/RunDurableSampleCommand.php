<?php

declare(strict_types=1);

namespace App\Command;

use App\Durable\DurableMessengerDrain;
use App\Durable\DurableSampleWorkflows;
use Gplanchat\Durable\Query\WorkflowQueryEvaluator;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\WorkflowRunMessage;
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
    description: 'Exécute un workflow exemple (équivalents légers des samples temporalio/samples-php)',
)]
final class RunDurableSampleCommand extends Command
{
    public function __construct(
        private readonly WorkflowRegistry $workflowRegistry,
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
                'Type enregistré dans WorkflowRegistry',
                DurableSampleWorkflows::GREETING,
            )
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Prénom (GreetingWorkflow)', 'World')
            ->addOption('first', null, InputOption::VALUE_REQUIRED, 'Premier prénom (ParallelGreetingWorkflow)', 'Alice')
            ->addOption('second', null, InputOption::VALUE_REQUIRED, 'Deuxième prénom (ParallelGreetingWorkflow)', 'Bob')
            ->addOption('text', null, InputOption::VALUE_REQUIRED, 'Texte (Echo / ParentCallsEchoChild)', 'from-parent')
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Délai timer en secondes (TimerThenTickWorkflow)', '0.01')
            ->addOption(
                'pause-seconds',
                null,
                InputOption::VALUE_REQUIRED,
                'Pause durable en secondes avant les enfants (ParallelChildEchoWorkflow ; 0 par défaut)',
                '0',
            )
            ->addOption('execution-id', null, InputOption::VALUE_REQUIRED, 'UUID d’exécution (sinon généré)')
            ->addOption(
                'no-drain',
                null,
                InputOption::VALUE_NONE,
                'Avec Messenger : n’exécute que le dispatch (consommateurs séparés : messenger:consume …)',
            )
            ->setHelp(
                <<<'HELP'
Ce projet illustre <info>gplanchat/durable</info> avec <comment>Symfony Messenger</comment> : les reprises de
workflow et les activités passent par les transports <info>durable_workflows</info> et
<info>durable_activities</info> (Doctrine DBAL / SQLite par défaut, voir <comment>config/packages/doctrine.yaml</comment>).

Avant la première exécution en <info>dev</info>, initialiser les tables du journal / métadonnées / lien parent-enfant :

  <info>php bin/console durable:schema:init</info>

Workflows disponibles (voir <info>App\Durable\DurableSampleWorkflows</info>) :

  <info>GreetingWorkflow</info>              — comme <comment>SimpleActivity</comment>
  <info>ParallelGreetingWorkflow</info>     — comme <comment>AsyncActivity</comment> (deux activités, <comment>all</comment>)
  <info>EchoChildWorkflow</info>             — enfant : majuscules via activité
  <info>ParentCallsEchoChildWorkflow</info>  — comme <comment>Child</comment>
  <info>ParallelChildEchoWorkflow</info>     — deux sous-workflows <comment>EchoChildWorkflow</comment> en <comment>all</comment>
  <info>TimerThenTickWorkflow</info>         — timer court puis activité
  <info>SideEffectRandomIdWorkflow</info>   — <comment>sideEffect</comment> rejouable

Sans <comment>--no-drain</comment>, cette commande vide localement les transports (équivalent court de
<info>php bin/console messenger:consume durable_workflows durable_activities</info>).

Démo HTTP + profiler Web : <info>php -S localhost:8000 -t public</info> puis ouvrir <info>/durable/profiler-demo</info>.

Référence amont : https://github.com/temporalio/samples-php
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

        $this->messageBus->dispatch(new WorkflowRunMessage($executionId, $workflowType, $payload));

        if ($input->getOption('no-drain')) {
            $io->note('Message WorkflowRunMessage dispatché. Lancez par exemple :');
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
            $io->warning('Drain Messenger : limite d’itérations atteinte ou exécution non terminée.');
        }
        $result = WorkflowQueryEvaluator::lastExecutionResult($this->eventStore, $executionId);
        if (null === $result) {
            $io->error('Aucun ExecutionCompleted dans le journal (échec ou drain incomplet).');

            return Command::FAILURE;
        }

        $io->success(\sprintf('Exécution %s terminée.', $executionId));
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
