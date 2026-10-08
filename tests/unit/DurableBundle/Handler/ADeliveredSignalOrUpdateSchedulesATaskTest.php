<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\Handler;

use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowUpdateHandler;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowTaskScheduled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use integration\Durable\Support\CallbackWorkflowResumeDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * A signal or an update waits for the next pass like any other resume: the journal says since when.
 */
final class ADeliveredSignalOrUpdateSchedulesATaskTest extends TestCase
{
    public function testASignalIsFollowedByTheTaskThatWillReadIt(): void
    {
        $journal = new InMemoryEventStore();

        (new DeliverWorkflowSignalHandler($journal, new CallbackWorkflowResumeDispatcher(static fn() => null)))(
            new DeliverWorkflowSignalMessage('exec-1', 'approve', [], 'req-1'),
        );

        self::assertSame([WorkflowSignalReceived::class, WorkflowTaskScheduled::class], $this->classes($journal));
    }

    public function testAnUpdateSchedulesTheTaskThatWillAnswerIt(): void
    {
        $journal = new InMemoryEventStore();

        (new DeliverWorkflowUpdateHandler(new CallbackWorkflowResumeDispatcher(static fn() => null), $journal))(
            new DeliverWorkflowUpdateMessage('exec-1', 'amend'),
        );

        self::assertSame([WorkflowTaskScheduled::class], $this->classes($journal));
    }

    /**
     * @return list<class-string>
     */
    private function classes(InMemoryEventStore $journal): array
    {
        return array_map(static fn(object $event): string => $event::class, iterator_to_array($journal->readStream(ExecutionId::fromString('exec-1')), false));
    }
}
