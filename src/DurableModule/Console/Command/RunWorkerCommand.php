<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Console\Command;

use Gplanchat\Durable\Magento\Runtime\RuntimeFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento durable:worker` — la boucle qui fait avancer les journaux.
 *
 * Sans elle, une exécution appendue au cluster y reste `running` pour toujours : son historique se
 * remplit, et personne ne répond aux tâches de sa file. C'est la moitié manquante du backend
 * Temporal sur cet hôte.
 *
 * **Pourquoi une commande, et pas un consommateur de la file de Magento.** Un worker tient sa tâche
 * par une longue interrogation, donc par construction plus longtemps qu'un message ordinaire — et
 * le §1.5 a mesuré ce que Magento fait d'un message tenu trop longtemps : la minuterie de reprise
 * ne demande à personne s'il a fini et le redistribue, deux processus traitant le même message en
 * même temps. Un worker ne peut donc pas être un message de file. Il est un processus long, drainé
 * par ce qu'un exploitant supervise déjà — systemd, supervisor, ou la même chose que ses
 * consommateurs.
 *
 * Les deux bornes existent pour cette supervision : un superviseur redémarre, il ne veut pas d'un
 * processus immortel qui garde une connexion gRPC vieille d'une semaine.
 */
/*
 * Pas `final` : le conteneur l'instancie, donc il engendre un `Interceptor` qui l'étend.
 */
class RunWorkerCommand extends Command
{
    private const OPTION_MAX_TASKS = 'max-tasks';
    private const OPTION_TIME_LIMIT = 'time-limit';

    public function __construct(
        private readonly RuntimeFactory $runtimeFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('durable:worker')
            ->setDescription('Polls the Temporal journal task queue and advances durable executions')
            ->addOption(
                self::OPTION_MAX_TASKS,
                null,
                InputOption::VALUE_REQUIRED,
                'Stop after this many workflow tasks. 0 means no limit.',
                '0',
            )
            ->addOption(
                self::OPTION_TIME_LIMIT,
                null,
                InputOption::VALUE_REQUIRED,
                'Stop after this many seconds. 0 means no limit.',
                '0',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maxTasks = (int) $input->getOption(self::OPTION_MAX_TASKS);
        $timeLimit = (int) $input->getOption(self::OPTION_TIME_LIMIT);

        // Le refus tombe ici plutôt qu'à la première itération : un worker sans grappe tournerait,
        // ne trouverait jamais rien, et aurait l'air parfaitement sain.
        $worker = $this->runtimeFactory->journalWorker();
        $deadline = $timeLimit > 0 ? microtime(true) + $timeLimit : null;

        $output->writeln(sprintf(
            '<info>durable:worker</info> polling the journal task queue%s%s',
            $maxTasks > 0 ? sprintf(', %d task(s) max', $maxTasks) : '',
            $deadline !== null ? sprintf(', %ds max', $timeLimit) : '',
        ));

        for ($processed = 0; $maxTasks === 0 || $processed < $maxTasks; ++$processed) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }

            $worker->processOne();
        }

        $output->writeln(sprintf('<info>%d task(s) processed.</info>', $processed));

        return Command::SUCCESS;
    }
}
