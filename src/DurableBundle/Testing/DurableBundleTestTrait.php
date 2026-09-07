<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Testing;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Query\WorkflowQueryEvaluator;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Uuid\NativeUuidV7Generator;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * PHPUnit trait for Symfony integration tests with the DurableBundle.
 *
 * To be used in a class that extends {@see \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase}.
 *
 * Prerequisites in `config/packages/messenger.yaml` (test env): in-memory Messenger transports
 * (e.g. `durable_workflows: 'in-memory://'`, `durable_activities: 'in-memory://'`).
 *
 * Usage:
 * ```php
 * final class MyWorkflowIntegrationTest extends KernelTestCase
 * {
 *     use DurableBundleTestTrait;
 *
 *     public function testGreetWorkflow(): void
 *     {
 *         self::bootKernel();
 *         $executionId = $this->dispatchWorkflow(
 *             MyGreetWorkflow::class,
 *             ['name' => 'World'],
 *         );
 *         $this->drainMessengerUntilSettled($executionId);
 *         $this->assertWorkflowResultEquals($executionId, 'Hello, World!');
 *     }
 * }
 * ```
 */
trait DurableBundleTestTrait
{
    /** @var list<string> Names of the Messenger transports to drain in test mode */
    private static array $durableWorkflowTransports = ['durable_workflows', 'durable_activities'];

    /** Max drain duration (seconds) before declaring failure */
    private static float $durableMaxDrainSeconds = 30.0;

    /**
     * Dispatches a workflow through the Messenger bus and returns its executionId.
     *
     * The workflow is identified by its PHP class. The type (alias) is resolved through
     * {@see WorkflowDefinitionLoader}.
     *
     * @param class-string $workflowClass
     * @param array<string, mixed> $input
     */
    protected function dispatchWorkflow(string $workflowClass, array $input = [], ?string $executionId = null): string
    {
        $executionId ??= (new NativeUuidV7Generator())->generate();
        $loader = new WorkflowDefinitionLoader();
        $workflowType = $loader->workflowTypeForClass($workflowClass);

        $this->getWorkflowResumeDispatcher()->dispatchNewWorkflowRun($executionId, $workflowType, $input);

        return $executionId;
    }

    /**
     * Drains the Messenger transports (durable_workflows + durable_activities) until the workflow
     * identified by $executionId is finished or the timeout is reached.
     *
     * To be called after {@see dispatchWorkflow} in a test running Messenger in-memory.
     *
     * @throws \RuntimeException if the workflow does not finish within the allotted time
     */
    protected function drainMessengerUntilSettled(string $executionId): void
    {
        $eventStore = $this->getEventStoreService();
        $metadataStore = $this->getWorkflowMetadataStore();
        $messageBus = $this->getMessageBus();
        $receiverLocator = $this->getMessengerReceiverLocator();

        $t0 = microtime(true);
        $hadMessage = false;
        $idleStreak = 0;

        while (microtime(true) - $t0 < self::$durableMaxDrainSeconds) {
            if (null !== WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)) {
                return;
            }

            $worked = $this->drainTransports($receiverLocator, $messageBus, $hadMessage);

            if (null !== WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)) {
                return;
            }

            if ($worked) {
                $idleStreak = 0;

                continue;
            }

            ++$idleStreak;
            if ($hadMessage && $idleStreak > 30 && !$metadataStore->hasActiveWorkflowMetadata($executionId)) {
                break;
            }

            if ($metadataStore->hasActiveWorkflowMetadata($executionId)) {
                usleep(100_000);
            } else {
                usleep(1_000);
            }
        }

        // Final check
        if (null === WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)) {
            // The workflow is allowed to fail (WorkflowExecutionFailed)
            $hasFailed = false;
            foreach ($eventStore->readStream($executionId) as $event) {
                if ($event instanceof WorkflowExecutionFailed) {
                    $hasFailed = true;
                    break;
                }
            }
            if (!$hasFailed) {
                throw new \RuntimeException(
                    \sprintf(
                        'The workflow "%s" did not finish within the allotted time (%ss).',
                        $executionId,
                        self::$durableMaxDrainSeconds,
                    ),
                );
            }
        }
    }

    /**
     * Checks that the workflow finished with the expected result.
     */
    protected function assertWorkflowResultEquals(string $executionId, mixed $expectedResult): void
    {
        $eventStore = $this->getEventStoreService();
        $completed = null;
        foreach ($eventStore->readStream($executionId) as $event) {
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
        $eventStore = $this->getEventStoreService();
        $failed = null;
        foreach ($eventStore->readStream($executionId) as $event) {
            if ($event instanceof WorkflowExecutionFailed) {
                $failed = $event;
                break;
            }
        }
        Assert::assertNotNull(
            $failed,
            \sprintf('The workflow "%s" did not fail (no WorkflowExecutionFailed in the journal).', $executionId),
        );

        if ('' !== $expectedFailureClass) {
            Assert::assertSame(
                $expectedFailureClass,
                $failed->failureClass(),
                \sprintf('The workflow "%s" failed with another class than the expected one.', $executionId),
            );
        }
    }

    /**
     * Returns the Durable DataCollector (profiler panel).
     *
     * Requires the kernel to be in debug mode and the profiler to be enabled.
     */
    protected function getDataCollector(): DurableDataCollector
    {
        return static::getContainer()->get(DurableDataCollector::class);
    }

    /**
     * Returns the EventStore of the test container.
     */
    protected function getEventStoreService(): EventStoreInterface
    {
        return static::getContainer()->get(EventStoreInterface::class);
    }

    private function getWorkflowResumeDispatcher(): WorkflowResumeDispatcher
    {
        return static::getContainer()->get(WorkflowResumeDispatcher::class);
    }

    private function getWorkflowMetadataStore(): WorkflowMetadataStore
    {
        return static::getContainer()->get(WorkflowMetadataStore::class);
    }

    private function getMessageBus(): MessageBusInterface
    {
        return static::getContainer()->get('messenger.default_bus');
    }

    private function getMessengerReceiverLocator(): ContainerInterface
    {
        return static::getContainer()->get('messenger.receiver_locator');
    }

    private function drainTransports(ContainerInterface $receiverLocator, MessageBusInterface $messageBus, bool &$hadMessage): bool
    {
        $worked = false;
        foreach (static::$durableWorkflowTransports as $transportName) {
            if (!$receiverLocator->has($transportName)) {
                continue;
            }
            $receiver = $receiverLocator->get($transportName);
            if (!$receiver instanceof TransportInterface) {
                continue;
            }
            foreach ($receiver->get() as $envelope) {
                try {
                    $messageBus->dispatch($envelope->with(new ReceivedStamp($transportName)));
                    $receiver->ack($envelope);
                } catch (\Throwable $e) {
                    $receiver->reject($envelope);

                    throw $e;
                }
                $hadMessage = true;
                $worked = true;
            }
        }

        return $worked;
    }
}
