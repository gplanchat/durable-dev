<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\Command;

use Gplanchat\Durable\Bundle\Command\DurableWorkerCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

/**
 * `durable:worker` works out which receivers the configured backend needs, says so, and hands them
 * to `messenger:consume` (#338, #263).
 */
final class DurableWorkerCommandTest extends TestCase
{
    public function testTheJournalBackendConsumesItsRoutedWorkflowsAndItsActivityTransport(): void
    {
        $consume = new RecordingConsumeCommand();
        $tester = $this->tester(
            $consume,
            routing: ['Gplanchat\Durable\Transport\ResumeWorkflowMessage' => ['durable_workflows'], 'Gplanchat\Durable\Transport\FireWorkflowTimersMessage' => ['durable_workflows']],
            transports: ['durable_workflows', 'durable_activities'],
            activityTransport: 'durable_activities',
        );

        self::assertSame(0, $tester->run(['command' => 'durable:worker', '--limit' => '5', '--time-limit' => '60', '--no-reset' => true]));
        self::assertSame(['durable_workflows', 'durable_activities'], $consume->receivers);
        self::assertSame(['limit' => '5', 'time-limit' => '60'], $consume->options);
        self::assertTrue($consume->noReset);
        self::assertStringContainsString('Consuming durable_workflows, durable_activities.', $tester->getDisplay());
    }

    public function testAWildcardRouteIsFollowed(): void
    {
        $consume = new RecordingConsumeCommand();
        $tester = $this->tester($consume, routing: ['*' => ['async']], transports: ['async'], activityTransport: null);

        self::assertSame(0, $tester->run(['command' => 'durable:worker']));
        self::assertSame(['async'], $consume->receivers);
    }

    public function testResumesRoutedToSyncOnlyLeaveNothingToConsume(): void
    {
        $consume = new RecordingConsumeCommand();
        $tester = $this->tester($consume, routing: ['Gplanchat\Durable\Transport\ResumeWorkflowMessage' => ['sync']], transports: ['sync'], activityTransport: null);

        self::assertSame(1, $tester->run(['command' => 'durable:worker']));
        self::assertNull($consume->receivers);
        self::assertStringContainsString('No transport consumes workflow resumes', $tester->getDisplay());
    }

    public function testResumesRoutedNowhereAreRefusedEvenWhenTheGuidesTransportExists(): void
    {
        $consume = new RecordingConsumeCommand();
        $tester = $this->tester($consume, routing: [], transports: ['durable_workflows', 'durable_activities'], activityTransport: 'durable_activities');

        self::assertSame(1, $tester->run(['command' => 'durable:worker']));
        self::assertNull($consume->receivers);
    }

    public function testARoleWithNothingToConsumeIsRefused(): void
    {
        $consume = new RecordingConsumeCommand();
        $tester = $this->tester($consume, routing: ['Gplanchat\Durable\Transport\ResumeWorkflowMessage' => ['durable_workflows']], transports: ['durable_workflows'], activityTransport: null);

        self::assertSame(1, $tester->run(['command' => 'durable:worker', '--role' => ['nexus']]));
        self::assertNull($consume->receivers);
        self::assertStringContainsString('Nothing to consume for the nexus role', $tester->getDisplay());
    }

    public function testAllIsEveryRoleAndTheWorkerNeverPrompts(): void
    {
        $consume = new RecordingConsumeCommand();
        $tester = $this->tester($consume, routing: [], transports: ['durable_workflows', 'durable_activities', 'durable_nexus'], activityTransport: null, temporal: true);

        self::assertSame(0, $tester->run(['command' => 'durable:worker', '--role' => ['all']]));
        self::assertSame(['durable_workflows', 'durable_activities', 'durable_nexus'], $consume->receivers);
        self::assertFalse($consume->interactive);
    }

    public function testTheTemporalBackendConsumesTheReceiversTheBundleRegistered(): void
    {
        $consume = new RecordingConsumeCommand();
        $tester = $this->tester($consume, routing: [], transports: ['durable_workflows', 'durable_activities', 'durable_nexus'], activityTransport: null, temporal: true);

        self::assertSame(0, $tester->run(['command' => 'durable:worker']));
        self::assertSame(['durable_workflows', 'durable_activities', 'durable_nexus'], $consume->receivers);
    }

    public function testARoleNarrowsTheReceivers(): void
    {
        $consume = new RecordingConsumeCommand();
        $tester = $this->tester($consume, routing: [], transports: ['durable_workflows', 'durable_activities', 'durable_nexus'], activityTransport: null, temporal: true);

        self::assertSame(0, $tester->run(['command' => 'durable:worker', '--role' => ['activity']]));
        self::assertSame(['durable_activities'], $consume->receivers);
    }

    public function testAnUnknownRoleIsRefused(): void
    {
        $consume = new RecordingConsumeCommand();
        $tester = $this->tester($consume, routing: [], transports: ['durable_workflows'], activityTransport: null);

        self::assertSame(1, $tester->run(['command' => 'durable:worker', '--role' => ['journal']]));
        self::assertStringContainsString('Unknown role "journal"', $tester->getDisplay());
    }

    public function testStopSignalsReachTheWorker(): void
    {
        $consume = new RecordingConsumeCommand();
        $worker = $this->application($consume, routing: [], transports: ['durable_workflows'], activityTransport: null)->find('durable:worker');
        self::assertInstanceOf(SignalableCommandInterface::class, $worker);

        self::assertSame($consume->getSubscribedSignals(), $worker->getSubscribedSignals());
        $worker->handleSignal(\SIGTERM);
        self::assertSame([\SIGTERM], $consume->signals);
    }

    /**
     * @param array<string, list<string>> $routing
     * @param list<string>                $transports
     */
    private function tester(RecordingConsumeCommand $consume, array $routing, array $transports, ?string $activityTransport, bool $temporal = false): ApplicationTester
    {
        return new ApplicationTester($this->application($consume, $routing, $transports, $activityTransport, $temporal));
    }

    /**
     * @param array<string, list<string>> $routing
     * @param list<string>                $transports
     */
    private function application(RecordingConsumeCommand $consume, array $routing, array $transports, ?string $activityTransport, bool $temporal = false): Application
    {
        $factories = [];
        foreach ($transports as $name) {
            $transport = 'sync' === $name ? new SyncTransport($this->createStub(MessageBusInterface::class)) : new InMemoryTransport();
            $factories[$name] = static fn() => $transport;
        }
        $locator = new ServiceLocator($factories);

        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommands([$consume, new DurableWorkerCommand(new SendersLocator($routing, $locator), $locator, $activityTransport, $temporal)]);

        return $application;
    }
}

/**
 * Stands for `messenger:consume`: records what it was asked to consume instead of consuming it.
 */
final class RecordingConsumeCommand extends Command implements SignalableCommandInterface
{
    /** @var list<string>|null */
    public ?array $receivers = null;

    /** @var array<string, string> */
    public array $options = [];

    public bool $noReset = false;

    public ?bool $interactive = null;

    /** @var list<int> */
    public array $signals = [];

    public function __construct()
    {
        parent::__construct('messenger:consume');
    }

    protected function configure(): void
    {
        $this->addArgument('receivers', InputArgument::IS_ARRAY);
        foreach (['limit', 'failure-limit', 'memory-limit', 'time-limit', 'sleep'] as $option) {
            $this->addOption($option, null, InputOption::VALUE_REQUIRED);
        }
        $this->addOption('no-reset', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->receivers = $input->getArgument('receivers');
        $this->options = array_filter($input->getOptions(), static fn(mixed $value): bool => \is_string($value));
        $this->noReset = (bool) $input->getOption('no-reset');
        $this->interactive = $input->isInteractive();

        return Command::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        return [\SIGTERM, \SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->signals[] = $signal;

        return false;
    }
}
