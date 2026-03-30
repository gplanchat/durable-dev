<?php

declare(strict_types=1);

namespace App\Command;

use App\Durable\DurableSampleWorkflows;
use Gplanchat\Durable\Port\WorkflowBackendInterface;
use Gplanchat\Durable\Query\WorkflowQueryEvaluator;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
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
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'durable:sample',
    description: 'Exécute un workflow exemple (équivalents légers des samples temporalio/samples-php)',
)]
final class RunDurableSampleCommand extends Command
{
    /** @var list<string> */
    private const DRAIN_TRANSPORTS = ['durable_workflows', 'durable_activities'];

    public function __construct(
        private readonly WorkflowRegistry $workflowRegistry,
        private readonly WorkflowBackendInterface $workflowBackend,
        private readonly MessageBusInterface $messageBus,
        private readonly EventStoreInterface $eventStore,
        private readonly WorkflowMetadataStore $workflowMetadataStore,
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ContainerInterface $receiverLocator,
        #[Autowire('%durable.distributed%')]
        private readonly bool $durableDistributed,
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
  <info>TimerThenTickWorkflow</info>         — timer court puis activité
  <info>SideEffectRandomIdWorkflow</info>   — <comment>sideEffect</comment> rejouable

Sans <comment>--no-drain</comment>, cette commande vide localement les transports (équivalent court de
<info>php bin/console messenger:consume durable_workflows durable_activities</info>).

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
                DurableSampleWorkflows::TIMER_THEN_TICK,
                DurableSampleWorkflows::SIDE_EFFECT_ID,
            ]);

            return Command::FAILURE;
        }

        $payload = $this->buildPayload($workflowType, $input);
        $executionId = (string) ($input->getOption('execution-id') ?: Uuid::v4());

        if ($this->durableDistributed) {
            $this->messageBus->dispatch(new WorkflowRunMessage($executionId, $workflowType, $payload));

            if ($input->getOption('no-drain')) {
                $io->note('Message WorkflowRunMessage dispatché. Lancez par exemple :');
                $io->text('  php bin/console messenger:consume durable_workflows durable_activities -vv');

                return Command::SUCCESS;
            }

            $this->drainUntilWorkflowSettled($executionId, $io);
            $result = WorkflowQueryEvaluator::lastExecutionResult($this->eventStore, $executionId);
            if (null === $result) {
                $io->error('Aucun ExecutionCompleted dans le journal (échec ou drain incomplet).');

                return Command::FAILURE;
            }
        } else {
            $handler = $this->workflowRegistry->getHandler($workflowType, $payload);
            $result = $this->workflowBackend->start($executionId, $handler, $workflowType);
        }

        $io->success(\sprintf('Exécution %s terminée.', $executionId));
        $io->writeln($this->formatResult($result));

        return Command::SUCCESS;
    }

    private function drainUntilWorkflowSettled(string $executionId, SymfonyStyle $io): void
    {
        $hadMessage = false;
        $idleStreak = 0;

        for ($round = 0; $round < 2000; ++$round) {
            // Mode distribué : les timers ne passent par checkTimers() que via ce message (voir FireWorkflowTimersHandler).
            $this->messageBus->dispatch(new FireWorkflowTimersMessage($executionId));

            $worked = false;
            foreach (self::DRAIN_TRANSPORTS as $transportName) {
                $receiver = $this->receiverLocator->get($transportName);
                foreach ($receiver->get() as $envelope) {
                    $this->messageBus->dispatch($envelope->with(new ReceivedStamp($transportName)));
                    $hadMessage = true;
                    $worked = true;
                }
            }

            if (null !== WorkflowQueryEvaluator::lastExecutionResult($this->eventStore, $executionId)) {
                return;
            }

            if ($worked) {
                $idleStreak = 0;

                continue;
            }

            ++$idleStreak;
            if ($hadMessage && $idleStreak > 30 && null === $this->workflowMetadataStore->get($executionId)) {
                return;
            }

            usleep(1000);
        }

        $io->warning('Drain Messenger : limite d’itérations atteinte.');
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
