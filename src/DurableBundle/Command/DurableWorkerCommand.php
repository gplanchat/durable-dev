<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Command;

use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
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
 * The work itself is `messenger:consume`: this command resolves the names, says them, and delegates.
 */
#[AsCommand(
    name: 'durable:worker',
    description: 'Consume the Durable workflow, activity and Nexus receivers of the configured backend.',
)]
final class DurableWorkerCommand extends Command
{
    public function __construct(
        private readonly SendersLocatorInterface $senders,
        private readonly ContainerInterface $receivers,
        private readonly ?string $activityTransport,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $byRole = [
            'workflow' => [...$this->routedTo(new ResumeWorkflowMessage('durable:worker')), ...$this->routedTo(new FireWorkflowTimersMessage('durable:worker')), 'durable_workflows'],
            'activity' => [...(null === $this->activityTransport ? [] : [$this->activityTransport]), 'durable_activities'],
            'nexus' => ['durable_nexus'],
        ];

        $receivers = [];
        foreach ($byRole as $role => $candidates) {
            $found = array_values(array_filter(array_unique($candidates), $this->receivers->has(...)));
            if ('workflow' === $role && [] === $found) {
                $output->writeln('<error>No transport consumes workflow resumes: route ResumeWorkflowMessage to an asynchronous transport in messenger.yaml, or set durable.temporal.dsn.</error>');

                return Command::FAILURE;
            }
            $receivers = [...$receivers, ...$found];
        }
        $receivers = array_values(array_unique($receivers));

        $output->writeln(\sprintf('Consuming %s.', implode(', ', $receivers)));

        return $this->consumeCommand()->run(new ArrayInput(['receivers' => $receivers]), $output);
    }

    /**
     * The transports a message is routed to, sync ones left out: nothing consumes them.
     *
     * @return list<string>
     */
    private function routedTo(object $message): array
    {
        $names = [];
        foreach ($this->senders->getSenders(new Envelope($message)) as $name => $sender) {
            if (!$sender instanceof SyncTransport) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    private function consumeCommand(): Command
    {
        return $this->getApplication()?->find('messenger:consume')
            ?? throw new \LogicException('durable:worker runs inside a console application that provides messenger:consume.');
    }
}
