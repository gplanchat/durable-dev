<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Console\Command;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento durable:worker` — la boucle qui fait avancer les exécutions.
 *
 * **Un processus, une file, un rôle.** `--role=journal` répond aux tâches de workflow, `--role=activity`
 * dépile les tâches d'activité. Les séparer n'est pas une préférence : ce sont deux files distinctes
 * côté Temporal, et un exploitant règle leur parallélisme séparément — une activité lente ne doit pas
 * retarder la reprise d'un journal.
 *
 * Sans le rôle `journal`, une exécution appendue au cluster n'avance pas : son historique se
 * remplit et personne ne répond à ses tâches. Sans le rôle `activity`, elle avance jusqu'à sa
 * première activité et s'y arrête — c'est exactement ce que le §5.3 avait mesuré, une commande
 * débitée dont le stock n'était jamais réservé.
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
    private const OPTION_ROLE = 'role';
    private const ROLE_JOURNAL = 'journal';
    private const ROLE_ACTIVITY = 'activity';
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
            ->setDescription('Polls a Temporal task queue and advances durable executions')
            ->addOption(
                self::OPTION_ROLE,
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Which queue to drain: %s or %s.', self::ROLE_JOURNAL, self::ROLE_ACTIVITY),
                self::ROLE_JOURNAL,
            )
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
        $role = (string) $input->getOption(self::OPTION_ROLE);
        // Les deux workers du pont ne nomment pas leur tour de la même façon — `processOne()` pour
        // le journal, `pollOnce()` pour les activités — et ce n'est pas à cette commande de leur
        // imposer un nom commun. Elle prend un tour, quel qu'il s'appelle.
        $tick = match ($role) {
            self::ROLE_JOURNAL => $this->runtimeFactory->journalWorker()->processOne(...),
            self::ROLE_ACTIVITY => $this->runtimeFactory->activityWorker()->pollOnce(...),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown worker role "%s". One process, one queue, one role: %s or %s.',
                $role,
                self::ROLE_JOURNAL,
                self::ROLE_ACTIVITY,
            )),
        };
        // La borne est une option de console, donc un entier de secondes ; `microtime()` rend un
        // flottant. Le cast est explicite parce que mélanger les deux en silence est exactement ce
        // qu'une analyse stricte refuse de laisser passer.
        $deadline = $timeLimit > 0 ? microtime(true) + (float) $timeLimit : null;

        $output->writeln(sprintf(
            '<info>durable:worker</info> polling the %s task queue%s%s',
            $role,
            $maxTasks > 0 ? sprintf(', %d task(s) max', $maxTasks) : '',
            $deadline !== null ? sprintf(', %ds max', $timeLimit) : '',
        ));

        for ($processed = 0; $maxTasks === 0 || $processed < $maxTasks; ++$processed) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }

            $tick();
        }

        $output->writeln(sprintf('<info>%d task(s) processed.</info>', $processed));

        return Command::SUCCESS;
    }
}
