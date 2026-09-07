<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Console\Command;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento durable:worker` — the loop that makes executions advance.
 *
 * **One process, one queue, one role.** `--role=journal` answers workflow tasks, `--role=activity`
 * drains activity tasks. Separating them is not a preference: these are two distinct queues on the
 * Temporal side, and an operator tunes their concurrency apart — a slow activity must not delay the
 * resume of a journal.
 *
 * Without the `journal` role, an execution appended to the cluster does not advance: its history
 * fills and no one answers its tasks. Without the `activity` role, it advances up to its first
 * activity and stops there — which is exactly what §5.3 had measured, an order charged whose stock
 * was never reserved.
 *
 * **Why a command, and not a consumer of Magento's queue.** A worker holds its task by long poll,
 * so by construction longer than an ordinary message — and §1.5 measured what Magento does with a
 * message held too long: the retry timer asks no one whether it has finished and redistributes it,
 * two processes handling the same message at the same time. So a worker cannot be a queue message.
 * It is a long-running process, drained by whatever an operator already supervises — systemd,
 * supervisor, or the same thing as their consumers.
 *
 * The two bounds exist for that supervision: a supervisor restarts, and it does not want an
 * immortal process holding a gRPC connection a week old.
 */
/*
 * Not `final`: the container instantiates it, so it generates an `Interceptor` extending it.
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

        // The refusal falls here rather than at the first iteration: a worker with no cluster
        // would run, would never find anything, and would look perfectly healthy.
        $role = (string) $input->getOption(self::OPTION_ROLE);
        // The bridge's two workers do not name their turn the same way — `processOne()` for the
        // journal, `pollOnce()` for activities — and it is not for this command to impose a common
        // name on them. It takes a turn, whatever it is called.
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
        // The bound is a console option, so an integer of seconds; `microtime()` returns a float.
        // The cast is explicit because silently mixing the two is exactly what a strict analysis
        // refuses to let through.
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
