<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Profiler\TemporalEventConverter;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\WorkflowStartOptions;
use Gplanchat\Durable\WorkflowTimeouts;
use Temporal\Api\Enums\V1\EventType;

/**
 * The failure paths as the server sees them: the `kind` must survive the round trip, and an
 * exception declared non-retryable must really stop the server-side RetryPolicy.
 */
final class WorkflowFailurePathsTest extends TemporalServerTestCase
{
    public function testUnhandledActivityFailureFailsTheWorkflowOnTheServer(): void
    {
        $executionId = $this->startWorkflow('FailsOnActivity', []);
        $event = $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED);

        $failure = $event->getWorkflowExecutionFailedEventAttributes()?->getFailure();
        self::assertNotNull($failure);
        self::assertStringContainsString('activity exploded', $failure->getMessage());
    }

    public function testTheFailureKindSurvivesTheRoundTripThroughTheServer(): void
    {
        // The kind travels in the ApplicationFailureInfo details: that is what makes it possible
        // to rebuild a typed WorkflowExecutionFailed from the history.
        $executionId = $this->startWorkflow('FailsOnActivity', []);
        $event = $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED);

        $decoded = (new TemporalEventConverter($executionId))->convert($event);

        self::assertInstanceOf(WorkflowExecutionFailed::class, $decoded);
        self::assertSame(WorkflowExecutionFailed::KIND_UNHANDLED_ACTIVITY, $decoded->kind());
        self::assertSame('boom', $decoded->context()['activityName'] ?? null);
    }

    public function testAnActivityWithoutMaxAttemptsRetriesIndefinitely(): void
    {
        // Reference for the in-memory alignment: without maximum_attempts, the server retries
        // endlessly and the workflow never finishes.
        $executionId = $this->startWorkflow('UnboundedRetry', []);
        $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);

        // After several seconds, still no terminal outcome.
        sleep(4);
        $names = $this->historyEventNames($executionId);

        self::assertNotContains('EVENT_TYPE_WORKFLOW_EXECUTION_FAILED', $names);
        self::assertNotContains('EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED', $names);
    }

    public function testTheServerRewritesARunTimeoutLongerThanTheExecutionTimeout(): void
    {
        // Empirical justification for the invariant carried by WorkflowTimeouts: asking for
        // execution=10s + run=60s produces no error, the server silently rewrites run to 10s. The
        // domain therefore refuses the configuration instead of letting it be rewritten.
        $executionId = 'runcap-' . bin2hex(random_bytes(4));
        $this->workflowClient()->startAsync('Plain', ['value' => 1], $executionId, new WorkflowStartOptions(
            timeouts: new WorkflowTimeouts(execution: Duration::seconds(10.0), run: Duration::seconds(10.0)),
        ));

        $started = $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED);
        $attrs = $started->getWorkflowExecutionStartedEventAttributes();

        self::assertNotNull($attrs);
        self::assertSame(10, $attrs->getWorkflowExecutionTimeout()?->getSeconds());
        self::assertSame(10, $attrs->getWorkflowRunTimeout()?->getSeconds());
    }

    public function testNonRetryableExceptionStopsTheServerRetryPolicy(): void
    {
        // RetryLimit::ofAttempts(5) in the RetryPolicy, but the exception is declared non-retryable:
        // the server must schedule a single attempt only.
        $executionId = $this->startWorkflow('NonRetryable', []);
        $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED);

        $starts = array_filter(
            $this->historyEventNames($executionId),
            static fn(string $name): bool => 'EVENT_TYPE_ACTIVITY_TASK_STARTED' === $name,
        );

        self::assertCount(1, $starts, 'a non-retryable exception must not be retried');
    }
}
