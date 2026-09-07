<?php

declare(strict_types=1);

namespace integration\Temporal;

use Temporal\Api\Enums\V1\EventType;

/**
 * The workflow-side deadline, against a real server.
 *
 * The case that cannot be delegated to a fake: a signal delivered *after* the deadline fired. Every
 * workflow task replays the execution from the start, so the history then holds both the fired
 * timer *and* the signal, and nothing but their order says which one settled the wait.
 *
 * The DUR032 guarantee, now carried by a condition and a handler: the test is the same, it asserts
 * the same thing, only its shape has changed.
 */
final class WorkflowDeadlineTest extends TemporalServerTestCase
{
    public function testASignalDeliveredAfterItsDeadlineDoesNotUndoTheTimeout(): void
    {
        $executionId = $this->startWorkflow('SignalDeadline', []);

        // The deadline has fired: what follows is recorded after it.
        $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_TIMER_FIRED);
        $this->workflowClient()->signal($this->workflowId($executionId), 'approve', ['by' => 'late']);

        $result = $this->workflowClient()->pollForCompletion($executionId, 250, 160);

        self::assertSame(['timeout'], $result['first'] ?? null, 'a late signal does not undo the deadline');
        self::assertSame(['signal', ['by' => 'late']], $result['second'] ?? null, 'it stays available for the next wait');
    }
}
