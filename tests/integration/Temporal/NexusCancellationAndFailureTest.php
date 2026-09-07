<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\DurableNexusOperationFailedException;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationFailureKind;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Nexus\V1\EndpointSpec;
use Temporal\Api\Nexus\V1\EndpointTarget;
use Temporal\Api\Nexus\V1\EndpointTarget\Worker;
use Temporal\Api\Operatorservice\V1\CreateNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\DeleteNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\OperatorServiceClient;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * §6.3 and §6.4 — cancellation reaches the server, and a failure says where it comes from.
 *
 * Same rig as {@see NexusOperationRoundTripTest}: the test creates its own Nexus endpoint, deletes
 * it on the way out, and drives the workflow tasks itself — no worker is running.
 *
 * What these two cases add to the round trip: cancellation requires the **real** `eventId` of the
 * scheduling, which no unit test can validate since it is the server that rejects a made-up
 * identifier; and the failure must surface typed, with its call site, all the way to the workflow.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §6.3 §6.4
 */
#[RequiresPhpExtension('grpc')]
final class NexusCancellationAndFailureTest extends TestCase
{
    private TemporalConnection $connection;
    private WorkflowServiceClient $client;
    private OperatorServiceClient $operator;
    private string $endpointName;
    private string $endpointId = '';
    private int $endpointVersion = 0;
    private ?string $workflowId = null;

    protected function setUp(): void
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS not set: no Temporal server.');
        }

        $queue = 'nexus-cf-' . bin2hex(random_bytes(5));
        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'durable-nexus-cancel',
            workflowTaskQueue: $queue,
            activityTaskQueue: $queue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
        $this->operator = new OperatorServiceClient($address, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);

        $this->endpointName = 'durable-cf-' . bin2hex(random_bytes(4));
        $worker = new Worker();
        $worker->setNamespace($this->connection->namespace->name());
        $worker->setTaskQueue($queue);
        $target = new EndpointTarget();
        $target->setWorker($worker);
        $spec = new EndpointSpec();
        $spec->setName($this->endpointName);
        $spec->setTarget($target);
        $request = new CreateNexusEndpointRequest();
        $request->setSpec($spec);

        $created = GrpcUnary::wait($this->operator->CreateNexusEndpoint($request, [], ['timeout' => 10_000_000]));
        $endpoint = $created->getEndpoint();
        self::assertNotNull($endpoint);
        $this->endpointId = $endpoint->getId();
        $this->endpointVersion = $endpoint->getVersion();
    }

    protected function tearDown(): void
    {
        if (null !== $this->workflowId) {
            $request = new TerminateWorkflowExecutionRequest();
            $request->setNamespace($this->connection->namespace->name());
            $request->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $this->workflowId]));
            $request->setReason('fin du test');

            try {
                GrpcUnary::wait($this->client->TerminateWorkflowExecution($request, [], ['timeout' => 10_000_000]));
            } catch (\RuntimeException) {
            }
        }

        if ('' !== $this->endpointId) {
            $request = new DeleteNexusEndpointRequest();
            $request->setId($this->endpointId);
            $request->setVersion($this->endpointVersion);

            try {
                GrpcUnary::wait($this->operator->DeleteNexusEndpoint($request, [], ['timeout' => 10_000_000]));
            } catch (\RuntimeException) {
            }
        }
    }

    public function testCancellationReachesTheServerWithTheRealScheduledEventId(): void
    {
        $operationId = 'op-' . bin2hex(random_bytes(4));
        $this->scheduleOperation($operationId, new NexusOperationTimeouts(scheduleToClose: Duration::minutes(5)));

        // A signal forces a new task: with no worker serving the endpoint, nothing else would
        // restart the execution, and there would be no task on which to place the cancellation.
        $this->signal();
        $task = $this->pollTask();

        // The buffer reads back the history of THIS task: that is where the real eventId comes
        // from, and a made-up identifier would make the server reject the whole task.
        $history = TemporalExecutionHistory::fromEvents(
            (new TemporalHistoryCursor($this->client, $this->connection))->eventsFromPoll($task),
        );
        $buffer = new TemporalWorkflowCommandBuffer($this->connection, 'exec-1', $history);
        // Since 1b.2, the identity of an operation is the eventId the server assigns, and not the
        // application identifier passed at scheduling time: it is the history that gives it.
        $identity = $history->findScheduledNexusOperation(0);
        self::assertNotNull($identity, "The scheduled operation is missing from the task's history.");
        $buffer->cancelNexusOperation($identity, 'race_superseded');
        $commands = $buffer->flush();
        self::assertCount(1, $commands, 'The buffer did not find the operation in the history.');

        $this->respond($task, $commands);

        self::assertTrue(
            $this->historyHas(EventType::EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUESTED),
            'The server accepted the command without recording the cancellation request: '
            . implode(', ', $this->historyNames()),
        );
    }

    public function testATimedOutOperationSurfacesTypedWithItsOrigin(): void
    {
        // A one-second bound on an endpoint nobody serves: the server ends up writing
        // NEXUS_OPERATION_TIMED_OUT, and that is the only failure that can be provoked without a
        // handler. What the test checks is downstream — that the read returns it typed.
        $operationId = 'op-' . bin2hex(random_bytes(4));
        $this->scheduleOperation($operationId, new NexusOperationTimeouts(scheduleToClose: Duration::seconds(1.0)));

        $deadline = microtime(true) + 45.0;
        while (microtime(true) < $deadline && !$this->historyHas(EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT)) {
            usleep(500_000);
        }
        self::assertTrue(
            $this->historyHas(EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT),
            'The server did not time the operation out: ' . implode(', ', $this->historyNames()),
        );

        $history = TemporalExecutionHistory::fromEvents(
            (new TemporalHistoryCursor($this->client, $this->connection))
                ->events(new WorkflowExecution(['workflow_id' => (string) $this->workflowId])),
        );
        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);

        $failure = $slot['failed'];
        self::assertInstanceOf(DurableNexusOperationFailedException::class, $failure, 'The failure must be typed, not bare.');
        self::assertSame(NexusOperationFailureKind::Timeout, $failure->kind());
        // The spec requires it: an uncaught failure must name the call site.
        self::assertSame($this->endpointName, $failure->endpoint());
        self::assertSame('billing', $failure->service());
        self::assertSame('charge', $failure->operation());
    }

    private function scheduleOperation(string $operationId, NexusOperationTimeouts $timeouts): void
    {
        $client = new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );
        $this->workflowId = $client->startAsync('NexusCancel', [], 'nexuscf-' . bin2hex(random_bytes(4)));

        $task = $this->pollTask();
        $buffer = new TemporalWorkflowCommandBuffer($this->connection, 'exec-1');
        $buffer->scheduleNexusOperation(
            $operationId,
            NexusEndpoint::named($this->endpointName),
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            ['amount' => 10],
            $timeouts,
            NexusOperationHeaders::none(),
        );
        $this->respond($task, $buffer->flush());
    }

    private function pollTask(): \Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse
    {
        $poll = new PollWorkflowTaskQueueRequest();
        $poll->setNamespace($this->connection->namespace->name());
        $poll->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $poll->setIdentity($this->connection->identity);

        $task = GrpcUnary::wait($this->client->PollWorkflowTaskQueue($poll, [], ['timeout' => 30_000_000]));
        self::assertNotSame('', $task->getTaskToken(), 'No workflow task served.');

        return $task;
    }

    /** @param list<\Temporal\Api\Command\V1\Command> $commands */
    private function respond(\Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse $task, array $commands): void
    {
        $done = new RespondWorkflowTaskCompletedRequest();
        $done->setNamespace($this->connection->namespace->name());
        $done->setTaskToken($task->getTaskToken());
        $done->setIdentity($this->connection->identity);
        $done->setCommands($commands);

        /** @var array{0: mixed, 1: \stdClass} $pair */
        $pair = $this->client->RespondWorkflowTaskCompleted($done, [], ['timeout' => 30_000_000])->wait();
        self::assertSame(
            0,
            (int) ($pair[1]->code ?? -1),
            \sprintf('The server refused the command: %s', (string) ($pair[1]->details ?? '')),
        );
    }

    private function signal(): void
    {
        $request = new \Temporal\Api\Workflowservice\V1\SignalWorkflowExecutionRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setWorkflowExecution(new WorkflowExecution(['workflow_id' => (string) $this->workflowId]));
        $request->setSignalName('poke');
        $request->setIdentity($this->connection->identity);
        GrpcUnary::wait($this->client->SignalWorkflowExecution($request, [], ['timeout' => 10_000_000]));
    }

    private function historyHas(int $type): bool
    {
        return \in_array(EventType::name($type), $this->historyNames(), true);
    }

    /** @return list<string> */
    private function historyNames(): array
    {
        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        $names = [];
        foreach ($cursor->events(new WorkflowExecution(['workflow_id' => (string) $this->workflowId])) as $event) {
            $names[] = EventType::name($event->getEventType());
        }

        return $names;
    }
}
