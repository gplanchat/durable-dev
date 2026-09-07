<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Durable\WorkflowStartOptions;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Base for the tests run against a **real** Temporal server.
 *
 * The driver was only ever checked at the protobuf level: well-formed commands, never submitted to
 * a server. Here they must be accepted.
 *
 *     temporal server start-dev --namespace durable-test --port 7233
 *     DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
 *
 * Skipped if the address is not provided.
 */
#[RequiresPhpExtension('grpc')]
abstract class TemporalServerTestCase extends TestCase
{
    protected TemporalConnection $connection;
    protected WorkflowServiceClient $client;

    /** @var list<string> the executions this test started, to be terminated on the way out */
    private array $startedExecutionIds = [];

    /** @var list<resource> */
    private array $workers = [];

    /** @var list<array{role: string, pipes: array<int, resource>}> */
    private array $workerPipes = [];

    protected function setUp(): void
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS not set: no Temporal server.');
        }

        // One queue per test: the workers of one case do not steal another case's tasks.
        $taskQueue = 'durable-it-' . bin2hex(random_bytes(6));

        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'durable-integration',
            workflowTaskQueue: $taskQueue,
            activityTaskQueue: $taskQueue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);

        $this->spawnWorker('workflow');
        $this->spawnWorker('activity');
    }

    protected function tearDown(): void
    {
        // Without this, a worker that dies at startup shows up as a plain silent timeout.
        if (!$this->status()->isSuccess()) {
            fwrite(\STDERR, $this->workerOutput());
        }

        $this->terminateStartedWorkflows();

        foreach ($this->workerPipes as $worker) {
            foreach ($worker['pipes'] as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
        foreach ($this->workers as $process) {
            if (\is_resource($process)) {
                proc_terminate($process, \SIGKILL);
                proc_close($process);
            }
        }
        $this->workers = [];
        $this->workerPipes = [];
    }

    /**
     * Terminates what the test started and did not carry through to its end.
     *
     * Without this, an unfinished execution stays `Running` **indefinitely** on a server that
     * several sessions share: the worker that served it dies with the test, so its tasks are no
     * longer taken, and the server reschedules them without end. A test whose very subject is a
     * task that fails in a loop thus leaves behind an execution that fails in a loop, forever.
     *
     * Measured on 2026-08-27: **more than a hundred abandoned executions** had piled up, one of
     * which had been retrying its task for three hours. Cleaning up is not a courtesy, it is what
     * stops one suite from degrading the environment of the next.
     *
     * Termination failures are swallowed: an already terminated execution is the normal case, and
     * failing a `tearDown` over that would mask the test's real result.
     */
    private function terminateStartedWorkflows(): void
    {
        foreach ($this->startedExecutionIds as $executionId) {
            $request = new TerminateWorkflowExecutionRequest();
            $request->setNamespace($this->connection->namespace->name());
            $request->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $this->workflowId($executionId)]));
            $request->setReason('fin du test');

            try {
                GrpcUnary::wait($this->client->TerminateWorkflowExecution($request, [], ['timeout' => 5_000_000]));
            } catch (\Throwable) {
                // Already terminated, or server unavailable: neither is the subject of the test.
            }
        }

        $this->startedExecutionIds = [];
    }

    protected function workflowClient(): WorkflowClient
    {
        return new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );
    }

    /**
     * Starts the workflow and returns its result, or fails with the message carried by the history.
     *
     * @param array<string, mixed> $input
     */
    protected function runWorkflow(string $workflowType, array $input, float $timeoutSeconds = 30.0): mixed
    {
        $executionId = strtolower($workflowType) . '-' . bin2hex(random_bytes(4));
        $this->workflowClient()->startAsync($workflowType, $input, $executionId);

        return $this->workflowClient()->pollForCompletion($executionId, 250, (int) ($timeoutSeconds * 4));
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function startWorkflow(string $workflowType, array $input, ?WorkflowStartOptions $options = null): string
    {
        $executionId = strtolower($workflowType) . '-' . bin2hex(random_bytes(4));
        $this->workflowClient()->startAsync($workflowType, $input, $executionId, $options);
        $this->startedExecutionIds[] = $executionId;

        return $executionId;
    }

    protected function workflowId(string $executionId): string
    {
        return $this->workflowClient()->workflowId($executionId);
    }

    /**
     * Waits for an event of the given type to appear in the history, and returns it.
     */
    protected function waitForHistoryEvent(string $executionId, int $eventType, float $timeoutSeconds = 30.0): HistoryEvent
    {
        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => $this->workflowId($executionId)]);
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            foreach ($cursor->events($execution) as $event) {
                if ($event->getEventType() === $eventType) {
                    return $event;
                }
            }
            usleep(250_000);
        }

        self::fail(\sprintf(
            'Event %s missing from the history of "%s" after %.0f s: %s',
            EventType::name($eventType),
            $executionId,
            $timeoutSeconds,
            implode(', ', $this->historyEventNames($executionId)),
        ));
    }

    /** @return list<string> */
    protected function historyEventNames(string $executionId): array
    {
        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => $this->workflowId($executionId)]);

        $names = [];
        foreach ($cursor->events($execution) as $event) {
            $names[] = EventType::name($event->getEventType());
        }

        return $names;
    }

    protected function workerOutput(): string
    {
        $out = '';
        foreach ($this->workerPipes as $worker) {
            foreach ($worker['pipes'] as $fd => $pipe) {
                if (!\is_resource($pipe)) {
                    continue;
                }
                $text = trim((string) stream_get_contents($pipe));
                if ('' !== $text) {
                    $out .= \sprintf("[worker %s fd%d]\n%s\n", $worker['role'], $fd, $text);
                }
            }
        }

        return $out;
    }

    /**
     * Replaces the workflow worker with another one, on a different code variant.
     *
     * This is a deployment, played out in miniature: the old process dies, the new one takes over
     * the same queue and the same execution. The activity worker, for its part, does not move — it
     * is not the one being redeployed.
     */
    protected function redeployWorkflowWorker(string $variant): void
    {
        foreach ($this->workers as $index => $process) {
            if ('workflow' !== ($this->workerPipes[$index]['role'] ?? '')) {
                continue;
            }
            if (\is_resource($process)) {
                proc_terminate($process, \SIGKILL);
                proc_close($process);
            }
            foreach ($this->workerPipes[$index]['pipes'] as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            unset($this->workers[$index], $this->workerPipes[$index]);
        }
        $this->workers = array_values($this->workers);
        $this->workerPipes = array_values($this->workerPipes);

        $this->spawnWorker('workflow', $variant);
    }

    /**
     * Starts a worker, possibly on a **code variant**.
     *
     * A replay divergence needs two versions of the same workflow type, and a worker lives in its
     * own process: the variant therefore travels through the environment, the way a deployment
     * would make it travel through an image.
     */
    protected function spawnWorker(string $role, string $variant = 'default'): void
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [
                \PHP_BINARY,
                __DIR__ . '/worker.php',
                $this->connection->target,
                $this->connection->namespace->name(),
                $this->connection->workflowTaskQueue,
                $role,
            ],
            $descriptors,
            $pipes,
            null,
            ['DURABLE_WORKER_VARIANT' => $variant] + getenv(),
        );

        if (!\is_resource($process)) {
            self::fail(\sprintf('Unable to start the %s worker.', $role));
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $this->workerPipes[] = ['role' => $role, 'pipes' => $pipes];
        $this->workers[] = $process;
    }
}
