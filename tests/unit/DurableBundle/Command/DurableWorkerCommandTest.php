<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\Command;

use Gplanchat\Durable\Bundle\Command\DurableWorkerCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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

        self::assertSame(0, $tester->run(['command' => 'durable:worker']));
        self::assertSame(['durable_workflows', 'durable_activities'], $consume->receivers);
        self::assertStringContainsString('Consuming durable_workflows, durable_activities.', $tester->getDisplay());
    }

    public function testResumesRoutedToSyncOnlyLeaveNothingToConsume(): void
    {
        $consume = new RecordingConsumeCommand();
        $tester = $this->tester($consume, routing: ['Gplanchat\Durable\Transport\ResumeWorkflowMessage' => ['sync']], transports: ['sync'], activityTransport: null);

        self::assertSame(1, $tester->run(['command' => 'durable:worker']));
        self::assertNull($consume->receivers);
        self::assertStringContainsString('No transport consumes workflow resumes', $tester->getDisplay());
    }

    /**
     * @param array<string, list<string>> $routing
     * @param list<string>                $transports
     */
    private function tester(RecordingConsumeCommand $consume, array $routing, array $transports, ?string $activityTransport): ApplicationTester
    {
        return new ApplicationTester($this->application($consume, $routing, $transports, $activityTransport));
    }

    /**
     * @param array<string, list<string>> $routing
     * @param list<string>                $transports
     */
    private function application(RecordingConsumeCommand $consume, array $routing, array $transports, ?string $activityTransport): Application
    {
        $factories = [];
        foreach ($transports as $name) {
            $transport = 'sync' === $name ? new SyncTransport($this->createStub(MessageBusInterface::class)) : new InMemoryTransport();
            $factories[$name] = static fn() => $transport;
        }
        $locator = new ServiceLocator($factories);

        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommands([$consume, new DurableWorkerCommand(new SendersLocator($routing, $locator), $locator, $activityTransport)]);

        return $application;
    }
}

/**
 * Stands for `messenger:consume`: records what it was asked to consume instead of consuming it.
 */
final class RecordingConsumeCommand extends Command
{
    /** @var list<string>|null */
    public ?array $receivers = null;

    public function __construct()
    {
        parent::__construct('messenger:consume');
    }

    protected function configure(): void
    {
        $this->addArgument('receivers', InputArgument::IS_ARRAY);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->receivers = $input->getArgument('receivers');

        return Command::SUCCESS;
    }

}
