<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Nexus\Serving\NexusOperationResponse;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Nexus\V1\EndpointSpec;
use Temporal\Api\Nexus\V1\EndpointTarget;
use Temporal\Api\Nexus\V1\EndpointTarget\Worker;
use Temporal\Api\Operatorservice\V1\CreateNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\DeleteNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\OperatorServiceClient;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollNexusTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;

/**
 * §4 — cancellation, measured then served.
 *
 * §1.5 had established the negative half: with the start task still pending, cancelling the caller
 * writes `NEXUS_OPERATION_CANCEL_REQUESTED` on its side and **no task reaches** the handler. The
 * operation had never started: nothing to cancel on its end.
 *
 * The positive half had never been observable, for want of a way to start an operation
 * asynchronously. That is now possible, and the two tests here read in order: the first measures
 * what the cancellation task carries — it **names the token returned at start** —, the second has
 * the worker make the gesture and checks that it reaches the workflow that carries the operation.
 */
#[RequiresPhpExtension('grpc')]
final class NexusServedCancellationTest extends TestCase
{
    private TemporalConnection $connection;
    private WorkflowServiceClientInterface $client;
    private OperatorServiceClient $operator;
    private string $endpointName;
    private string $endpointId = '';
    private int $endpointVersion = 0;
    private string $queue;
    /** @var list<string> */
    private array $started = [];

    protected function setUp(): void
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS not set: no Temporal server.');
        }

        $this->queue = 'nexus-cancel-' . bin2hex(random_bytes(5));
        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'durable-nexus-cancel',
            workflowTaskQueue: $this->queue,
            activityTaskQueue: $this->queue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
        $this->operator = new OperatorServiceClient($address, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);

        $this->endpointName = 'durable-cx-' . bin2hex(random_bytes(4));
        $worker = new Worker();
        $worker->setNamespace($this->connection->namespace->name());
        $worker->setTaskQueue($this->queue);
        $target = new EndpointTarget();
        $target->setWorker($worker);
        $spec = new EndpointSpec();
        $spec->setName($this->endpointName);
        $spec->setTarget($target);
        $request = new CreateNexusEndpointRequest();
        $request->setSpec($spec);

        $created = $this->operator->CreateNexusEndpoint($request, [], ['timeout' => 10_000_000]);
        $endpoint = $created->getEndpoint();
        self::assertNotNull($endpoint);
        $this->endpointId = $endpoint->getId();
        $this->endpointVersion = $endpoint->getVersion();
    }

    protected function tearDown(): void
    {
        foreach ($this->started as $workflowId) {
            $request = new TerminateWorkflowExecutionRequest();
            $request->setNamespace($this->connection->namespace->name());
            $request->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $workflowId]));
            $request->setReason('fin de la sonde');

            try {
                $this->client->TerminateWorkflowExecution($request, [], ['timeout' => 10_000_000]);
            } catch (\RuntimeException) {
            }
        }

        if ('' !== $this->endpointId) {
            $request = new DeleteNexusEndpointRequest();
            $request->setId($this->endpointId);
            $request->setVersion($this->endpointVersion);

            try {
                $this->operator->DeleteNexusEndpoint($request, [], ['timeout' => 10_000_000]);
            } catch (\RuntimeException) {
            }
        }
    }

    public function testACancelTaskArrivesForAStartedOperationAndNamesTheToken(): void
    {
        $fulfillerId = 'cancel-fulfiller-' . bin2hex(random_bytes(4));

        $registry = NexusOperationRegistry::routedBy('temporal');
        $registry->register(
            NexusService::named('probe'),
            NexusOperationName::named('slow'),
            static fn(mixed $payload): NexusOperationResponse => NexusOperationResponse::fulfilledByWorkflow('SlowWork', [], $fulfillerId),
        );

        $callerId = $this->scheduleOperation();
        $this->worker($registry)->pollOnce();
        $this->started[] = $fulfillerId;

        // The operation has started: the caller must see NEXUS_OPERATION_STARTED before
        // cancelling makes any sense. That is exactly the condition §1.5 had found missing.
        self::assertTrue(
            $this->awaitEvent($callerId, \Temporal\Api\Enums\V1\EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED),
            'The operation did not start: the probe is not testing what it thinks it is.',
        );

        $this->requestCancellationFromTheCaller($callerId);

        $task = $this->pollNexusTask();
        $cancel = $task?->getRequest()?->getCancelOperation();

        self::assertNotNull($cancel, 'No cancel_operation task arrived for a started operation.');
        self::assertSame('probe', $cancel->getService());
        self::assertSame('slow', $cancel->getOperation());
        self::assertSame(
            $fulfillerId,
            $cancel->getOperationToken(),
            'The cancellation task must name the token returned at start — it is the only handle on what carries the operation.',
        );
    }

    public function testTheWorkerCancelsTheWorkflowThatCarriesTheOperation(): void
    {
        $fulfillerId = 'cancel-fulfiller-' . bin2hex(random_bytes(4));

        $registry = NexusOperationRegistry::routedBy('temporal');
        $registry->register(
            NexusService::named('probe'),
            NexusOperationName::named('slow'),
            static fn(): NexusOperationResponse => NexusOperationResponse::fulfilledByWorkflow('SlowWork', [], $fulfillerId),
        );

        $callerId = $this->scheduleOperation();
        $worker = $this->worker($registry);
        $worker->pollOnce();
        $this->started[] = $fulfillerId;

        self::assertTrue(
            $this->awaitEvent($callerId, \Temporal\Api\Enums\V1\EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED),
            'The operation did not start.',
        );
        self::assertFalse(
            $this->awaitEvent($fulfillerId, \Temporal\Api\Enums\V1\EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCEL_REQUESTED, 2),
            'The workflow was not supposed to be cancelled yet.',
        );

        $this->requestCancellationFromTheCaller($callerId);

        // The same worker, on the cancellation task this time. `pollOnce()` is one poll and one
        // only: on an empty queue it hands back control without a word (§1.2), and the
        // cancellation task does not necessarily show up on the first call.
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $worker->pollOnce();
            if ($this->awaitEvent($fulfillerId, \Temporal\Api\Enums\V1\EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCEL_REQUESTED, 2)) {
                break;
            }
        }

        self::assertTrue(
            $this->awaitEvent($fulfillerId, \Temporal\Api\Enums\V1\EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCEL_REQUESTED),
            'Cancelling the operation must cancel the workflow that carries it.',
        );
    }

    private function worker(NexusOperationRegistry $registry): TemporalNexusWorker
    {
        return new TemporalNexusWorker(
            new WorkflowServiceNexusRpc($this->client),
            $this->connection,
            $registry,
        );
    }

    private function scheduleOperation(): string
    {
        $client = new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );
        $callerId = (string) $client->startAsync('NexusCancelCaller', [], 'nxcancel-' . bin2hex(random_bytes(4)));
        $this->started[] = $callerId;

        $task = $this->pollWorkflowTask();
        $buffer = new TemporalWorkflowCommandBuffer($this->connection, 'exec-1');
        $buffer->scheduleNexusOperation(
            'op-' . bin2hex(random_bytes(4)),
            NexusEndpoint::named($this->endpointName),
            NexusService::named('probe'),
            NexusOperationName::named('slow'),
            [],
            new NexusOperationTimeouts(scheduleToClose: Duration::minutes(5)),
            NexusOperationHeaders::none(),
        );
        $this->respondToWorkflowTask($task, $buffer->flush());

        return $callerId;
    }

    private function requestCancellationFromTheCaller(string $callerId): void
    {
        // A signal forces a new task: without it, nothing would restart the execution and there
        // would be no task on which to place the cancellation.
        $signal = new \Temporal\Api\Workflowservice\V1\SignalWorkflowExecutionRequest();
        $signal->setNamespace($this->connection->namespace->name());
        $signal->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $callerId]));
        $signal->setSignalName('reveille');
        $signal->setIdentity($this->connection->identity);
        $this->client->SignalWorkflowExecution($signal, [], ['timeout' => 10_000_000]);

        $task = $this->pollWorkflowTaskFor($callerId);
        // The complete history, and not the task's: the page the poll returns after a signal does
        // not restart from the beginning, and the operation's scheduling is upstream. The buffer
        // only needs the eventId, which is the same in both reads.
        $history = TemporalExecutionHistory::fromEvents(
            (new TemporalHistoryCursor($this->client, $this->connection))
                ->events(new WorkflowExecution(['workflow_id' => $callerId])),
        );
        $buffer = new TemporalWorkflowCommandBuffer($this->connection, 'exec-1', $history);
        $identity = $history->findScheduledNexusOperation(0);
        self::assertNotNull($identity, 'The scheduled operation is missing from the task history.');
        $buffer->cancelNexusOperation($identity, 'race_superseded');
        $commands = $buffer->flush();
        self::assertCount(1, $commands, 'The buffer did not produce the cancellation command.');
        $this->respondToWorkflowTask($task, $commands);
    }

    private function awaitEvent(string $workflowId, int $type, int $attempts = 40): bool
    {
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $cursor = new TemporalHistoryCursor($this->client, $this->connection);
            foreach ($cursor->events(new WorkflowExecution(['workflow_id' => $workflowId])) as $event) {
                if ($type === (int) $event->getEventType()) {
                    return true;
                }
            }
            usleep(250_000);
        }

        return false;
    }

    private function pollNexusTask(): ?\Temporal\Api\Workflowservice\V1\PollNexusTaskQueueResponse
    {
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $poll = new PollNexusTaskQueueRequest();
            $poll->setNamespace($this->connection->namespace->name());
            $poll->setTaskQueue(new TaskQueue(['name' => $this->queue]));
            $poll->setIdentity($this->connection->identity);

            $task = $this->client->PollNexusTaskQueue($poll, [], ['timeout' => 30_000_000]);
            if ('' !== (string) $task->getTaskToken()) {
                return $task;
            }
        }

        return null;
    }

    /**
     * The workflow that fulfils the operation runs on the **same queue** as the caller: a bare poll
     * can return its task. Answering that one with a command that speaks of the caller's history
     * makes the whole task be rejected — the server then says the operation is "non-existing",
     * which sends you looking for a defect where there is none.
     */
    private function pollWorkflowTaskFor(string $workflowId): PollWorkflowTaskQueueResponse
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $task = $this->pollWorkflowTask();
            if ($workflowId === (string) $task->getWorkflowExecution()?->getWorkflowId()) {
                return $task;
            }
        }

        self::fail("No workflow task for {$workflowId}.");
    }

    private function pollWorkflowTask(): PollWorkflowTaskQueueResponse
    {
        $poll = new PollWorkflowTaskQueueRequest();
        $poll->setNamespace($this->connection->namespace->name());
        $poll->setTaskQueue(new TaskQueue(['name' => $this->queue]));
        $poll->setIdentity($this->connection->identity);

        return $this->client->PollWorkflowTaskQueue($poll, [], ['timeout' => 30_000_000]);
    }

    /**
     * @param list<\Temporal\Api\Command\V1\Command> $commands
     */
    private function respondToWorkflowTask(PollWorkflowTaskQueueResponse $task, array $commands): void
    {
        $done = new RespondWorkflowTaskCompletedRequest();
        $done->setNamespace($this->connection->namespace->name());
        $done->setTaskToken($task->getTaskToken());
        $done->setIdentity($this->connection->identity);
        $done->setCommands($commands);
        $this->client->RespondWorkflowTaskCompleted($done, [], ['timeout' => 30_000_000]);
    }
}
