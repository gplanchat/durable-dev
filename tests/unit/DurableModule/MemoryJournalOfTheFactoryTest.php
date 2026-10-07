<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use PHPUnit\Framework\TestCase;
use unit\DurableModule\Fixture\OrderActivities;
use unit\DurableModule\Fixture\OrderWorkflow;
use unit\DurableModule\Fixture\RecordingOrderActivities;

/**
 * Pins what the in-memory journal and catalogue of a factory keep, and for how long (#985).
 *
 * Pins the current behaviour pending the id-reuse decision (#985); expected to change.
 */
final class MemoryJournalOfTheFactoryTest extends TestCase
{
    public function testEveryRunStaysInTheCatalogueAndItsEventsInTheJournalForTheLifeOfTheFactory(): void
    {
        $factory = $this->factory(new RecordingOrderActivities());
        $runtime = $factory->create();
        $runtime->run(OrderWorkflow::class, ['orderId' => 'A'], 'order-a');
        $runtime->run(OrderWorkflow::class, ['orderId' => 'B'], 'order-b');

        self::assertNotNull($factory->catalog()->findRun(ExecutionId::fromString('order-a')));
        self::assertNotNull($factory->catalog()->findRun(ExecutionId::fromString('order-b')));
        self::assertNotSame([], iterator_to_array($runtime->eventStore()->readStream(ExecutionId::fromString('order-a')), false));
    }

    public function testRunningTheSameIdAgainOnAnotherRuntimeOfTheFactoryReplaysTheFirstResultWithoutExecutingAgain(): void
    {
        $activities = new class implements OrderActivities {
            public int $charges = 0;

            public function charge(string $orderId): string
            {
                ++$this->charges;

                return 'charge:' . $orderId;
            }

            public function reserveStock(string $orderId): string
            {
                return 'reserve:' . $orderId;
            }

            public function notifyCustomer(string $receipt): string
            {
                return 'notify:' . $receipt;
            }
        };
        $factory = $this->factory($activities);

        $first = $factory->create()->run(OrderWorkflow::class, ['orderId' => 'A'], 'order-1');
        $second = $factory->create()->run(OrderWorkflow::class, ['orderId' => 'A'], 'order-1');

        self::assertSame('notify:charge:A', $first);
        self::assertSame($first, $second);
        self::assertSame(1, $activities->charges);
    }

    public function testRunningTheSameIdAgainWithAnotherInputOnTheSameFactoryFailsOnReplayDivergence(): void
    {
        $factory = $this->factory(new RecordingOrderActivities());
        $factory->create()->run(OrderWorkflow::class, ['orderId' => 'A'], 'order-1');

        $this->expectException(WorkflowTaskFailure::class);
        $factory->create()->run(OrderWorkflow::class, ['orderId' => 'B'], 'order-1');
    }

    public function testARunThatFailsIsFoundInTheCatalogueAsFailed(): void
    {
        $factory = $this->factory(new class implements OrderActivities {
            public function charge(string $orderId): string
            {
                throw new \DomainException('card declined');
            }

            public function reserveStock(string $orderId): string
            {
                return '';
            }

            public function notifyCustomer(string $receipt): string
            {
                return '';
            }
        });

        try {
            $factory->create()->run(OrderWorkflow::class, ['orderId' => 'A'], 'order-f');
            self::fail('The run should throw.');
        } catch (DurableWorkflowAlgorithmFailureException) {
        }

        $run = $factory->catalog()->findRun(ExecutionId::fromString('order-f'));
        self::assertNotNull($run);
        self::assertSame(WorkflowRunStatus::Failed, $run->status);
    }

    private function factory(OrderActivities $activities): RuntimeFactory
    {
        return new RuntimeFactory(workflowClasses: [OrderWorkflow::class], activityHandlers: [$activities], maxActivityRetries: 1);
    }
}
