<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalGrpcTimeouts;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * A probe, not a feature: the "workflow-conditions-and-handler-dispatch" change makes interleaving
 * — applying a message, then re-evaluating the pending conditions — the core of its loop (§4.2).
 * That loop only makes sense if a **single** workflow task can carry several journalled messages:
 * otherwise the order would be imposed by the server, one task per message, and there would be
 * nothing to interleave on the domain side.
 *
 * The property is MEASURED against a real server and cannot be deduced from the protos: what
 * `PollWorkflowTaskQueueResponse` is able to represent does not say what the server emits.
 *
 * ⚠ What the probe establishes is that the batched regime is **reachable**, not that it is
 * guaranteed: the same probe with a worker listening returns one signal per task, that worker
 * claiming each task before the next one arrives. The number of messages per task is an artefact of
 * worker availability, not a contract — and that is precisely what forbids ordering messages by
 * task boundary. See the "probed" section of the design.
 *
 * No worker is started here, deliberately — that is what lets the signals pile up on the pending
 * task. The test polls the queue itself and reads the batch the server hands it.
 *
 * @see openspec/changes/workflow-conditions-and-handler-dispatch/tasks.md §1.2
 * @see openspec/changes/workflow-conditions-and-handler-dispatch/design.md
 */
#[RequiresPhpExtension('grpc')]
final class WorkflowTaskMessageBatchTest extends TestCase
{
    private const SIGNAL_COUNT = 3;

    private TemporalConnection $connection;
    private WorkflowServiceClient $client;
    private ?string $workflowId = null;

    protected function setUp(): void
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS not set: no Temporal server.');
        }

        $taskQueue = 'durable-probe-' . bin2hex(random_bytes(6));

        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'durable-probe-1-2',
            workflowTaskQueue: $taskQueue,
            activityTaskQueue: $taskQueue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
    }

    protected function tearDown(): void
    {
        if (null === $this->workflowId) {
            return;
        }

        // The execution has no worker: without termination it would stay open on the server.
        $req = new TerminateWorkflowExecutionRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $this->workflowId]));
        $req->setReason('fin de sonde');
        $req->setIdentity($this->connection->identity);

        try {
            GrpcUnary::wait($this->client->TerminateWorkflowExecution($req, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US]));
        } catch (\RuntimeException) {
            // Cleanup must not mask the test's verdict.
        }
    }

    public function testOneWorkflowTaskCarriesSeveralSignals(): void
    {
        $this->workflowId = $this->startWithoutWorker();

        // First task: the start. We complete it with no command, so that the execution stays open
        // and no task is in flight when the signals arrive.
        $first = $this->pollOnce();
        self::assertNotSame('', $first->getTaskToken(), 'No workflow task returned for the start.');
        $this->completeWithoutCommands($first);

        for ($i = 0; $i < self::SIGNAL_COUNT; ++$i) {
            $this->signal('probe-' . $i, ['rang' => $i]);
        }

        $second = $this->pollOnce();
        self::assertNotSame('', $second->getTaskToken(), 'No workflow task returned after the signals.');

        // The poll returns the COMPLETE history: counting the signals over the whole batch would
        // only prove that there have been several since the start, not that ONE task carries them
        // all. Only the segment this task has to process counts — what follows the last
        // WORKFLOW_TASK_COMPLETED.
        $segment = $this->pendingSegment($second);
        $signalled = $this->countIn($segment, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED);
        $started = $this->countIn($segment, EventType::EVENT_TYPE_WORKFLOW_TASK_STARTED);

        self::assertSame(
            1,
            $started,
            'The pending segment carries several tasks: the measurement would no longer say what ONE task carries.',
        );
        self::assertSame(
            self::SIGNAL_COUNT,
            $signalled,
            \sprintf(
                'A single workflow task did not carry the %d messages: %d in its segment. '
                . 'Interleaving would then be imposed by the server, one task per message, and §4.2 would '
                . 'have no object left. Segment: %s',
                self::SIGNAL_COUNT,
                $signalled,
                implode(', ', array_map(EventType::name(...), $segment)),
            ),
        );
    }

    private function startWithoutWorker(): string
    {
        $client = new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );

        // The type does not have to exist: the server journals the start without running anything
        // as long as no worker polls — which is precisely the situation we want.
        return $client->startAsync('ProbeMessageBatch', [], 'probe-' . bin2hex(random_bytes(4)));
    }

    private function pollOnce(): PollWorkflowTaskQueueResponse
    {
        $req = new PollWorkflowTaskQueueRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $req->setIdentity($this->connection->identity);

        $resp = GrpcUnary::wait($this->client->PollWorkflowTaskQueue($req, [], ['timeout' => TemporalGrpcTimeouts::LONG_POLL_US]));
        self::assertInstanceOf(PollWorkflowTaskQueueResponse::class, $resp);

        return $resp;
    }

    private function completeWithoutCommands(PollWorkflowTaskQueueResponse $poll): void
    {
        $req = new RespondWorkflowTaskCompletedRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setTaskToken($poll->getTaskToken());
        $req->setIdentity($this->connection->identity);

        GrpcUnary::wait($this->client->RespondWorkflowTaskCompleted($req, [], ['timeout' => TemporalGrpcTimeouts::RESPOND_WORKFLOW_TASK_US]));
    }

    /** @param array<string, mixed> $args */
    private function signal(string $name, array $args): void
    {
        $req = new \Temporal\Api\Workflowservice\V1\SignalWorkflowExecutionRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setWorkflowExecution(new WorkflowExecution(['workflow_id' => (string) $this->workflowId]));
        $req->setSignalName($name);
        $req->setIdentity($this->connection->identity);
        $req->setInput(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($args)));

        GrpcUnary::wait($this->client->SignalWorkflowExecution($req, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US]));
    }

    /**
     * The event types that follow the last WORKFLOW_TASK_COMPLETED: what this task has to
     * process, as opposed to the already processed history that the poll also returns.
     *
     * @return list<int>
     */
    private function pendingSegment(PollWorkflowTaskQueueResponse $poll): array
    {
        $segment = [];
        foreach ($poll->getHistory()?->getEvents() ?? [] as $event) {
            if (EventType::EVENT_TYPE_WORKFLOW_TASK_COMPLETED === $event->getEventType()) {
                $segment = [];

                continue;
            }
            $segment[] = $event->getEventType();
        }

        return $segment;
    }

    /** @param list<int> $segment */
    private function countIn(array $segment, int $eventType): int
    {
        return \count(array_filter($segment, static fn(int $type): bool => $type === $eventType));
    }
}
