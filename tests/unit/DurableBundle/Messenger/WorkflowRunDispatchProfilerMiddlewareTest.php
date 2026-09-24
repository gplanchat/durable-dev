<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\Messenger;

use Gplanchat\Durable\Bundle\Messenger\MessengerWorkflowResumeDispatcher;
use Gplanchat\Durable\Bundle\Messenger\WorkflowRunDispatchProfilerMiddleware;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBus;

/**
 * A run that just started is recorded as a new run with its type, not as a resume with an empty type:
 * the panel printed "Messenger resume … no type in the message" for it (#337, B-4).
 *
 * @internal
 */
final class WorkflowRunDispatchProfilerMiddlewareTest extends TestCase
{
    public function testANewRunIsRecordedAsANewRunWithItsType(): void
    {
        [$dispatcher, $trace] = $this->dispatcher();

        $dispatcher->dispatchNewWorkflowRun('exec-1', 'App\\OrderWorkflow', ['order' => 42]);

        $dispatch = $trace->getTimeline()[0];
        self::assertFalse($dispatch['isResume']);
        self::assertSame('App\\OrderWorkflow', $dispatch['workflowType']);
    }

    public function testAResumeIsStillRecordedAsAResume(): void
    {
        [$dispatcher, $trace] = $this->dispatcher();

        $dispatcher->dispatchResume('exec-1');

        $dispatch = $trace->getTimeline()[0];
        self::assertTrue($dispatch['isResume']);
        self::assertSame('', $dispatch['workflowType']);
    }

    /**
     * @return array{MessengerWorkflowResumeDispatcher, DurableExecutionTrace}
     */
    private function dispatcher(): array
    {
        $trace = new DurableExecutionTrace();
        $bus = new MessageBus([new WorkflowRunDispatchProfilerMiddleware($trace)]);

        return [new MessengerWorkflowResumeDispatcher($bus, new InMemoryWorkflowMetadataStore()), $trace];
    }
}
