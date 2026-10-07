<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Testing;

use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Laravel\Queue\InProcessWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Testing\JournalAssertions;
use Gplanchat\Durable\Uuid\NativeUuidV7Generator;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Assert;

/**
 * PHPUnit trait for Laravel tests of a workflow against the application's configured backend.
 *
 * To be used in a class that extends Laravel's `Illuminate\Foundation\Testing\TestCase` (or
 * Testbench's), which provides `$this->app`. It offers the four operations of the Symfony bundle's
 * `DurableBundleTestTrait`: start ({@see dispatchWorkflow()}), drain ({@see drainUntilSettled()}),
 * read the result ({@see assertWorkflowResultEquals()}) and assert on the journal
 * ({@see assertWorkflowFailed()}).
 *
 * The drain depends on the backend: on `memory` it drives the process's own queue
 * (`InProcessWorkflowResumeDispatcher::drain()`, what `durable:drain` calls); on `illuminate` it
 * runs `queue:work --stop-when-empty` until the run settles.
 */
trait DurableLaravelTestTrait
{
    /** Max drain duration (seconds) before declaring failure. */
    private static float $durableMaxDrainSeconds = 30.0;

    /**
     * Starts a workflow and returns its executionId. The run is only queued: call
     * {@see drainUntilSettled()} to drive it.
     *
     * @param class-string|string  $workflowClass a workflow class or its declared type
     * @param array<string, mixed> $input
     */
    protected function dispatchWorkflow(string $workflowClass, array $input = [], ?string $executionId = null): string
    {
        $executionId ??= (new NativeUuidV7Generator())->generate();
        $workflowType = (new WorkflowDefinitionLoader())->aliasForTemporalInterop($workflowClass);

        $this->app->make(WorkflowResumeDispatcher::class)
            ->dispatchNewWorkflowRun(ExecutionId::fromString($executionId), $workflowType, $input);

        return $executionId;
    }

    /**
     * Drives the run until it completes or fails.
     *
     * @throws \RuntimeException if the run is still open when the drain ends or the timeout is reached
     */
    protected function drainUntilSettled(string $executionId): void
    {
        $dispatcher = $this->app->make(WorkflowResumeDispatcher::class);
        $deadline = microtime(true) + self::$durableMaxDrainSeconds;

        do {
            if ($dispatcher instanceof InProcessWorkflowResumeDispatcher) {
                try {
                    $dispatcher->drain();
                } catch (\Throwable $e) {
                    // A failing workflow ends the drain by throwing: that is an outcome to assert on,
                    // unless the journal does not hold this run's failure.
                    if (!$this->durableHasFailed($executionId)) {
                        throw $e;
                    }
                }
            } else {
                // The queue Durable dispatches to, in the sense of config/queue.php; null takes the defaults.
                $queue = $this->app['config']->get('durable.queue', []);
                $arguments = ['--stop-when-empty' => true];
                if (null !== ($queue['connection'] ?? null)) {
                    $arguments['connection'] = $queue['connection'];
                }
                if (null !== ($queue['name'] ?? null)) {
                    $arguments['--queue'] = $queue['name'];
                }
                $this->app->make(Kernel::class)->call('queue:work', $arguments);
            }
            if ($this->durableIsSettled($executionId)) {
                return;
            }
            // The memory drain already waited out its own budget: a second pass would only repeat it.
            if ($dispatcher instanceof InProcessWorkflowResumeDispatcher) {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException(\sprintf('The workflow "%s" did not finish within the allotted time.', $executionId));
    }

    /**
     * Checks that the workflow finished with the expected result.
     */
    protected function assertWorkflowResultEquals(string $executionId, mixed $expectedResult): void
    {
        $completed = null;
        foreach ($this->getEventStoreService()->readStream(ExecutionId::fromString($executionId)) as $event) {
            if ($event instanceof ExecutionCompleted) {
                $completed = $event;
                break;
            }
        }
        Assert::assertNotNull(
            $completed,
            \sprintf('The workflow "%s" did not finish (no ExecutionCompleted in the journal).', $executionId),
        );
        Assert::assertEquals(
            $expectedResult,
            $completed->result(),
            \sprintf('The workflow "%s" did not return what was expected.', $executionId),
        );
    }

    /**
     * Checks that the workflow failed.
     *
     * @param class-string<\Throwable>|'' $expectedFailureClass
     */
    protected function assertWorkflowFailed(string $executionId, string $expectedFailureClass = ''): void
    {
        JournalAssertions::assertWorkflowFailed($this->getEventStoreService(), $executionId, $expectedFailureClass);
    }

    /**
     * Returns the application's event store, for low-level inspection.
     */
    protected function getEventStoreService(): EventStoreInterface
    {
        return $this->app->make(EventStoreInterface::class);
    }

    private function durableHasFailed(string $executionId): bool
    {
        foreach ($this->getEventStoreService()->readStream(ExecutionId::fromString($executionId)) as $event) {
            if ($event instanceof WorkflowExecutionFailed) {
                return true;
            }
        }

        return false;
    }

    private function durableIsSettled(string $executionId): bool
    {
        foreach ($this->getEventStoreService()->readStream(ExecutionId::fromString($executionId)) as $event) {
            if ($event instanceof ExecutionCompleted || $event instanceof WorkflowExecutionFailed) {
                return true;
            }
        }

        return false;
    }
}
