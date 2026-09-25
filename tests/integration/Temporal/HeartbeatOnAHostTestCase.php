<?php

declare(strict_types=1);

namespace integration\Temporal;

use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\WorkflowExecutionStatus;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;

/**
 * The heartbeat scenarios of #510 against a real server, with the activities hosted by a
 * framework's activity worker rather than by this suite's own (#518). The workflows run on the
 * suite's workflow worker; only the activity side changes host.
 *
 * The workers are killed in the parent's tearDown(), which runs whether the test passed or not.
 */
abstract class HeartbeatOnAHostTestCase extends TemporalServerTestCase
{
    private const MARKER_TIMEOUT_SECONDS = 30.0;

    /** @var list<string> */
    private array $markers = [];

    protected function tearDown(): void
    {
        foreach ($this->markers as $marker) {
            @unlink($marker);
        }
        parent::tearDown();
    }

    /**
     * Thirty seconds of heartbeats, one a second, under a five-second heartbeat timeout and a single
     * attempt: the activity completes only if its heartbeats reach the server through the sender its
     * host injected. With the no-op sender, it fails on its heartbeat timeout.
     */
    public function testAHeartbeatingActivityOutlivesItsHeartbeatTimeout(): void
    {
        self::assertSame(['beats' => 30], $this->runWithin('HeartbeatsThroughItsTimeout', ['seconds' => 30], 90.0));
    }

    /**
     * The workflow's deadline cancels the activity it outlives; the server hands the request to the
     * activity's next heartbeat, and sendHeartbeat() returns true.
     */
    public function testACancellationRequestedByTheServerReachesSendHeartbeat(): void
    {
        $marker = $this->markers[] = sys_get_temp_dir() . '/durable-heartbeat-' . bin2hex(random_bytes(6));

        self::assertSame(['deadline' => true], $this->runWithin('CancelsItsHeartbeatingActivity', ['marker' => $marker], 60.0));

        $deadline = microtime(true) + self::MARKER_TIMEOUT_SECONDS;
        while (!is_file($marker) && microtime(true) < $deadline) {
            usleep(250_000);
        }
        self::assertFileExists($marker, \sprintf('No heartbeat of the %s-hosted activity reported the cancellation within %.0f s.', $this->activityWorkerRole(), self::MARKER_TIMEOUT_SECONDS));
        self::assertSame('cancel-requested', file_get_contents($marker));
    }

    /**
     * Starts the workflow and returns its result, failing after `$seconds` of wall-clock time: the
     * status is read with a describe, which does not block, and the result only once the run closed.
     *
     * @param array<string, mixed> $input
     */
    private function runWithin(string $workflowType, array $input, float $seconds): mixed
    {
        $executionId = $this->startWorkflow($workflowType, $input);
        $request = new DescribeWorkflowExecutionRequest([
            'namespace' => $this->connection->namespace->name(),
            'execution' => new WorkflowExecution(['workflow_id' => $this->workflowId($executionId)]),
        ]);

        $deadline = microtime(true) + $seconds;
        do {
            $status = $this->client->DescribeWorkflowExecution($request, [], ['timeout' => 5_000_000])->getWorkflowExecutionInfo()?->getStatus();
            if (WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING !== $status) {
                return $this->workflowClient()->pollForCompletion($executionId, 0, 1);
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        self::fail(\sprintf('The %s run did not close within %.0f s; its activities are hosted by the %s worker.', $workflowType, $seconds, $this->activityWorkerRole()));
    }
}
