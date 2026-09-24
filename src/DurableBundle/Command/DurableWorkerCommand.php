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
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

/**
 * Consumes what the configured backend needs, without asking the operator to know its transport names.
 *
 * The names come from the backend. On a journal backend, workflow resumes and timers go wherever the
 * application routes them, so the Messenger routing is asked, sync transports excluded, and
 * activities go to the transport the bundle was configured with. On Temporal, the bundle registers
 * `durable_workflows`, `durable_activities` and `durable_nexus` as receivers with no routing at all.
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
        private readonly bool $temporal = false,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these roles: workflow, activity, nexus, or all (the default).', []);
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

        $requested = $input->getOption('role');
        $roles = [] === $requested || \in_array('all', $requested, true) ? self::ROLES : $requested;
        if ([] !== $unknown = array_diff($roles, self::ROLES)) {
            $output->writeln(\sprintf('<error>Unknown role "%s": expected workflow, activity, nexus or all.</error>', implode('", "', $unknown)));

            return Command::FAILURE;
        }

        $byRole = $this->temporal
            ? ['workflow' => ['durable_workflows'], 'activity' => ['durable_activities'], 'nexus' => ['durable_nexus']]
            : [
                'workflow' => [...$this->routedTo(new ResumeWorkflowMessage('durable:worker')), ...$this->routedTo(new FireWorkflowTimersMessage('durable:worker'))],
                'activity' => null === $this->activityTransport ? [] : [$this->activityTransport],
                'nexus' => ['durable_nexus'],
            ];

        $receivers = [];
        foreach ($roles as $role) {
            $found = array_values(array_filter(array_unique($byRole[$role]), $this->receivers->has(...)));
            if ('workflow' === $role && [] === $found) {
                $output->writeln('<error>No transport consumes workflow resumes: route ResumeWorkflowMessage to an asynchronous transport in messenger.yaml, or set durable.temporal.dsn.</error>');

                return Command::FAILURE;
            }
            // Asked for by name, an empty role is a mistake; left to the default, it is only unused.
            if ([] === $found && [] !== $requested && !\in_array('all', $requested, true)) {
                $output->writeln(\sprintf('<error>Nothing to consume for the %s role with this configuration.</error>', $role));

                return Command::FAILURE;
            }
            $receivers = [...$receivers, ...$found];
        }
        $receivers = array_values(array_unique($receivers));

        $output->writeln(\sprintf('Consuming %s.', implode(', ', $receivers)));
        if ($this->temporal && (null !== $input->getOption('limit') || null !== $input->getOption('failure-limit'))) {
            // The Temporal receivers answer their task inside get() and hand Messenger no message.
            $output->writeln('<comment>--limit and --failure-limit never stop a worker on Temporal: its receivers hand no message to Messenger. Use --time-limit or --memory-limit, checked when the current long poll returns.</comment>');
        }

        $arguments = ['receivers' => $receivers];
        foreach (self::FORWARDED_OPTIONS as $option) {
            if (null !== $value = $input->getOption($option)) {
                $arguments['--' . $option] = $value;
            }
        }
        foreach (self::FORWARDED_FLAGS as $flag) {
            if ($input->getOption($flag)) {
                // A bare flag: since Messenger 8.1 --no-reset takes an optional interval, and `true`
                // would read as "reset after every message".
                $arguments['--' . $flag] = null;
            }
        }

        // Nested, the command must not prompt: under a supervisor there is nobody to answer.
        $consumeInput = new ArrayInput($arguments);
        $consumeInput->setInteractive(false);

        return $this->consumeCommand()->run($consumeInput, $output);
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        // Without Messenger there is no worker to stop, and execute() says what is missing.
        return $this->messengerConsume()?->getSubscribedSignals() ?? [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        return $this->messengerConsume()?->handleSignal($signal, $previousExitCode) ?? false;
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
     * The command that holds the worker, so the one the stop signals must reach. Console 6.4 commands
     * do not all handle signals, Messenger's has since 6.3.
     */
    private function messengerConsume(): ?ConsumeMessagesCommand
    {
        if (!$this->getApplication()?->has('messenger:consume')) {
            return null;
        }
        $consume = $this->consumeCommand();

        return $consume instanceof ConsumeMessagesCommand ? $consume : null;
    }

    /**
     * `messenger:consume` itself, unwrapped from the lazy proxy FrameworkBundle registers.
     */
    private function consumeCommand(): Command
    {
        $consume = $this->getApplication()?->find('messenger:consume')
            ?? throw new \LogicException('durable:worker runs inside a console application that provides messenger:consume.');

        return $consume instanceof LazyCommand ? $consume->getCommand() : $consume;
    }
}
