<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Command\V1\ScheduleNexusOperationCommandAttributes;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\WorkflowTaskFailedCause;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * A probe, not a feature: the "temporal-nexus-support" change promises that the failures of a Nexus
 * operation reach the workflow as typed failures. An unknown endpoint is **not one of them**, and
 * that is what this probe establishes.
 *
 * Measured against Temporal 1.31.2: the command is refused at `RespondWorkflowTaskCompleted` with
 * INVALID_ARGUMENT, the history records a WORKFLOW_TASK_FAILED with cause
 * BAD_SCHEDULE_NEXUS_OPERATION_ATTRIBUTES, and the task is **re-served**, its `attempt` climbing at
 * each turn. No Nexus operation is ever scheduled, so no operation failure can be delivered: the
 * workflow does not fall over, it spins.
 *
 * Design consequence: a `NexusEndpoint` value object can do nothing about it — the name is well
 * formed, it is the endpoint that does not exist. Only a check before the command is emitted, or a
 * deliberate acceptance of the loop, covers this case.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §1.2
 */
#[RequiresPhpExtension('grpc')]
final class NexusUnknownEndpointTest extends TestCase
{
    private const GRPC_INVALID_ARGUMENT = 3;

    private TemporalConnection $connection;
    private WorkflowServiceClient $client;
    private ?string $workflowId = null;

    protected function setUp(): void
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS not set: no Temporal server.');
        }

        $queue = 'nexus-unknown-' . bin2hex(random_bytes(5));
        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'nexus-unknown-probe',
            workflowTaskQueue: $queue,
            activityTaskQueue: $queue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
    }

    protected function tearDown(): void
    {
        if (null === $this->workflowId) {
            return;
        }

        // Without a worker, the execution would stay open, retrying its task indefinitely.
        $req = new TerminateWorkflowExecutionRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $this->workflowId]));
        $req->setReason('fin de sonde');

        try {
            GrpcUnary::wait($this->client->TerminateWorkflowExecution($req, [], ['timeout' => 10_000_000]));
        } catch (\RuntimeException) {
            // Cleanup does not mask the verdict.
        }
    }

    public function testSchedulingOnAnUnknownEndpointFailsTheWorkflowTaskAndKeepsRetrying(): void
    {
        $this->workflowId = $this->startWithoutWorker();
        $first = $this->pollOnce();

        $error = $this->respondWithNexusCommand($first);

        self::assertNotNull($error, 'The server accepted an unknown endpoint.');
        self::assertSame(self::GRPC_INVALID_ARGUMENT, $error['code']);
        self::assertStringContainsString('BadScheduleNexusOperationAttributes', $error['message']);
        self::assertStringContainsString('not found', $error['message']);

        // The history carries the failure, with its cause named.
        $failed = $this->findEvent(EventType::EVENT_TYPE_WORKFLOW_TASK_FAILED);
        self::assertNotNull($failed, 'No WORKFLOW_TASK_FAILED recorded.');
        self::assertSame(
            WorkflowTaskFailedCause::WORKFLOW_TASK_FAILED_CAUSE_BAD_SCHEDULE_NEXUS_OPERATION_ATTRIBUTES,
            $failed->getWorkflowTaskFailedEventAttributes()?->getCause(),
        );

        // And the task comes back: it is a loop, not a halt. The workflow never falls over.
        $retry = $this->pollOnce();
        self::assertNotSame('', $retry->getTaskToken(), 'The task was not re-served.');
        self::assertGreaterThan(
            $first->getAttempt(),
            $retry->getAttempt(),
            'The attempt counter did not climb: this would not be the same task retried.',
        );

        // No Nexus operation was scheduled — so there is no operation failure to type.
        self::assertNull(
            $this->findEvent(EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED),
            'A Nexus operation was scheduled even though the endpoint is unknown.',
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

        return $client->startAsync('NexusUnknownEndpointProbe', [], 'nexusunknown-' . bin2hex(random_bytes(4)));
    }

    private function pollOnce(): PollWorkflowTaskQueueResponse
    {
        $req = new PollWorkflowTaskQueueRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $req->setIdentity($this->connection->identity);

        $resp = GrpcUnary::wait($this->client->PollWorkflowTaskQueue($req, [], ['timeout' => 30_000_000]));
        self::assertInstanceOf(PollWorkflowTaskQueueResponse::class, $resp);

        return $resp;
    }

    /** @return array{code: int, message: string}|null */
    private function respondWithNexusCommand(PollWorkflowTaskQueueResponse $poll): ?array
    {
        $attrs = new ScheduleNexusOperationCommandAttributes();
        // A name WELL FORMED for the server regex: what is missing is the endpoint itself.
        $attrs->setEndpoint('absent-endpoint-' . bin2hex(random_bytes(4)));
        $attrs->setService('un-service');
        $attrs->setOperation('une-operation');

        $command = new Command();
        $command->setCommandType(CommandType::COMMAND_TYPE_SCHEDULE_NEXUS_OPERATION);
        $command->setScheduleNexusOperationCommandAttributes($attrs);

        $req = new RespondWorkflowTaskCompletedRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setTaskToken($poll->getTaskToken());
        $req->setIdentity($this->connection->identity);
        $req->setCommands([$command]);

        /** @var array{0: mixed, 1: \stdClass} $pair */
        $pair = $this->client->RespondWorkflowTaskCompleted($req, [], ['timeout' => 30_000_000])->wait();
        $status = $pair[1];
        $code = (int) ($status->code ?? -1);

        return 0 === $code ? null : ['code' => $code, 'message' => (string) ($status->details ?? '')];
    }

    private function findEvent(int $eventType): ?\Temporal\Api\History\V1\HistoryEvent
    {
        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => (string) $this->workflowId]);

        foreach ($cursor->events($execution) as $event) {
            if ($event->getEventType() === $eventType) {
                return $event;
            }
        }

        return null;
    }
}
