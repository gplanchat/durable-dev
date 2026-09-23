<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Command;

use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

/**
 * Consumes what the configured backend needs, without asking the operator to know its transport names.
 *
 * The names come from three places. Workflow resumes and timers go wherever the application routes
 * them, so the Messenger routing is asked, sync transports excluded. Activities go to the transport
 * the bundle was configured with. On Temporal, the bundle registers `durable_workflows`,
 * `durable_activities` and `durable_nexus` as receivers with no routing at all.
 *
 * The work itself is `messenger:consume`: this command resolves the names, says them, and delegates,
 * forwarding the stop signals so a supervisor's SIGTERM still ends the worker cleanly.
 */
#[AsCommand(
    name: 'durable:worker',
    description: 'Consume the Durable workflow, activity and Nexus receivers of the configured backend.',
)]
final class DurableWorkerCommand extends Command implements SignalableCommandInterface
{
    private const ROLES = ['workflow', 'activity', 'nexus'];

    /** Options passed through to `messenger:consume` as they are. */
    private const FORWARDED_OPTIONS = ['limit', 'failure-limit', 'memory-limit', 'time-limit', 'sleep'];

    /** Flags passed through to `messenger:consume`. */
    private const FORWARDED_FLAGS = ['no-reset'];

    public function __construct(
        private readonly ?SendersLocatorInterface $senders,
        private readonly ?ContainerInterface $receivers,
        private readonly ?string $activityTransport,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these roles: workflow, activity, nexus (all by default).', []);
        foreach (self::FORWARDED_OPTIONS as $option) {
            $this->addOption($option, null, InputOption::VALUE_REQUIRED, \sprintf('Passed to messenger:consume --%s.', $option));
        }
        foreach (self::FORWARDED_FLAGS as $flag) {
            $this->addOption($flag, null, InputOption::VALUE_NONE, \sprintf('Passed to messenger:consume --%s.', $flag));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (null === $this->senders || null === $this->receivers) {
            $output->writeln('<error>durable:worker needs Symfony Messenger: enable framework.messenger.</error>');

            return Command::FAILURE;
        }

        $roles = $input->getOption('role') ?: self::ROLES;
        if ([] !== $unknown = array_diff($roles, self::ROLES)) {
            $output->writeln(\sprintf('<error>Unknown role "%s": expected workflow, activity or nexus.</error>', implode('", "', $unknown)));

            return Command::FAILURE;
        }

        $byRole = [
            'workflow' => [...$this->routedTo(new ResumeWorkflowMessage('durable:worker')), ...$this->routedTo(new FireWorkflowTimersMessage('durable:worker')), 'durable_workflows'],
            'activity' => [...(null === $this->activityTransport ? [] : [$this->activityTransport]), 'durable_activities'],
            'nexus' => ['durable_nexus'],
        ];

        $receivers = [];
        foreach ($roles as $role) {
            $found = array_values(array_filter(array_unique($byRole[$role]), $this->receivers->has(...)));
            if ('workflow' === $role && [] === $found) {
                $output->writeln('<error>No transport consumes workflow resumes: route ResumeWorkflowMessage to an asynchronous transport in messenger.yaml, or set durable.temporal.dsn.</error>');

                return Command::FAILURE;
            }
            $receivers = [...$receivers, ...$found];
        }
        $receivers = array_values(array_unique($receivers));

        $output->writeln(\sprintf('Consuming %s.', implode(', ', $receivers)));

        $arguments = ['receivers' => $receivers];
        foreach (self::FORWARDED_OPTIONS as $option) {
            if (null !== $value = $input->getOption($option)) {
                $arguments['--' . $option] = $value;
            }
        }
        foreach (self::FORWARDED_FLAGS as $flag) {
            if ($input->getOption($flag)) {
                $arguments['--' . $flag] = true;
            }
        }

        return $this->consumeCommand()->run(new ArrayInput($arguments), $output);
    }

    public function getSubscribedSignals(): array
    {
        return $this->consumeCommand()->getSubscribedSignals();
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        return $this->consumeCommand()->handleSignal($signal, $previousExitCode);
    }

    /**
     * The transports a message is routed to, sync ones left out: nothing consumes them.
     *
     * @return list<string>
     */
    private function routedTo(object $message): array
    {
        $names = [];
        foreach ($this->senders?->getSenders(new Envelope($message)) ?? [] as $name => $sender) {
            if (!$sender instanceof SyncTransport) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * `messenger:consume` itself, unwrapped from the lazy proxy FrameworkBundle registers: it is the
     * command that holds the worker, so it is the one the stop signals must reach.
     */
    private function consumeCommand(): Command
    {
        $consume = $this->getApplication()?->find('messenger:consume')
            ?? throw new \LogicException('durable:worker runs inside a console application that provides messenger:consume.');

        return $consume instanceof LazyCommand ? $consume->getCommand() : $consume;
    }
}
