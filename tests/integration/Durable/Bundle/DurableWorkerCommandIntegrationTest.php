<?php

declare(strict_types=1);

namespace integration\Durable\Bundle;

use Gplanchat\Durable\Bundle\Command\DurableWorkerCommand;
use Gplanchat\Durable\Bundle\DurableBundle;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;
use integration\Durable\Bundle\Support\GreetByWorkerWorkflow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The guide's profile, asynchronous transports included: a started workflow sits in its queue until
 * `durable:worker` consumes it, and the command needs no transport name to do so (#338).
 *
 * @internal
 */
#[CoversClass(DurableWorkerCommand::class)]
final class DurableWorkerCommandIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DurableWorkerTestKernel::class;
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        self::$class = null;
        self::$kernel = null;
        self::$booted = false;
        restore_exception_handler();
    }

    #[Test]
    public function theWorkerDrivesAStartedWorkflowToCompletion(): void
    {
        $kernel = self::bootKernel();
        $container = self::getContainer();
        $container->get(\Gplanchat\Durable\WorkflowRegistry::class)->registerClass(GreetByWorkerWorkflow::class);
        $container->get(\Gplanchat\Durable\ActivityExecutor::class)->register('greet', static fn(array $p): string => 'Hello, ' . $p['name'] . '!');

        $executionId = '01900000-0000-7000-8000-0000000000d1';
        $container->get(WorkflowResumeDispatcher::class)->dispatchNewWorkflowRun($executionId, 'greet-by-worker', ['name' => 'Ada']);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        // Resume, activity, resume: three messages, then the worker stops.
        // --no-reset keeps the in-memory transports between messages. Here the dispatch and the
        // worker share one process; a deployment uses a transport that crosses processes instead.
        self::assertSame(0, $tester->run(['command' => 'durable:worker', '--limit' => '3', '--time-limit' => '10', '--no-reset' => true]));
        self::assertStringContainsString('Consuming durable_workflows, durable_activities.', $tester->getDisplay());
        self::assertSame('Hello, Ada!', $this->completedWith($container->get(EventStoreInterface::class), $executionId));
    }

    /**
     * #444: consumed with a reset after each message, an in-memory transport loses the activity
     * the workflow just queued, and the run hangs on ActivityScheduled with no error. Both
     * commands are refused instead; `durable:worker` runs `messenger:consume` without a console
     * event, so the refusal has to come from the worker itself.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function consumersWithReset(): iterable
    {
        yield 'durable:worker' => [['command' => 'durable:worker', '--limit' => '3', '--time-limit' => '10']];
        yield 'messenger:consume' => [['command' => 'messenger:consume', 'receivers' => ['durable_workflows', 'durable_activities'], '--limit' => '3', '--time-limit' => '10']];
    }

    /** @param array<string, mixed> $input */
    #[Test]
    #[DataProvider('consumersWithReset')]
    public function aResetOnAnInMemoryDurableTransportIsRefused(array $input): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);
        $tester = new ApplicationTester($application);

        self::assertNotSame(0, $tester->run($input, ['capture_stderr_separately' => true]));
        // The console wraps the message to its width, mid-word included: unwrap before reading.
        $display = (string) preg_replace('/ *\n */', '', $tester->getErrorOutput());
        self::assertStringContainsString('in-memory', $display);
        self::assertStringContainsString('--no-reset', $display);
    }

    private function completedWith(EventStoreInterface $store, string $executionId): mixed
    {
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof ExecutionCompleted) {
                return $event->result();
            }
        }

        return null;
    }
}

final class DurableWorkerTestKernel extends \Symfony\Component\HttpKernel\Kernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DurableBundle(),
        ];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
        $loader->load(__DIR__ . '/config/durable_distributed_messenger_activities.php');
    }
}
