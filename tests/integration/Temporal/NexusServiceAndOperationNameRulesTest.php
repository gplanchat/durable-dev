<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Command\V1\ScheduleNexusOperationCommandAttributes;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\CommandType;
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
 * A probe, not a feature: the second half of §1.1, the half the endpoint probe could not reach. The
 * service and operation names travel inside the `ScheduleNexusOperation` command, so an endpoint
 * that exists and a completed workflow task are needed to submit them to the server.
 *
 * Verdict, against Temporal 1.31.2: **the server validates neither of them**. Empty, a space, edge
 * whitespace, a tab, a control character, a slash, an accent, a thousand characters — everything is
 * accepted, and `NEXUS_OPERATION_SCHEDULED` records the name **verbatim**.
 *
 * This is the exact opposite of the endpoint, whose regex the server states and which it refuses at
 * creation time — and it is the failure mode of {@see \Gplanchat\Durable\TaskQueue}, to the letter:
 * accepted without a flinch, never served. An operation scheduled on a badly named service waits
 * for a handler that will never match it, without a single line of error.
 *
 * Consequence for §2: `NexusService` and `NexusOperationName` must be **stricter than the server**,
 * like `TaskQueue` and unlike `NexusEndpoint`. The three names of one and the same command
 * therefore do not follow the same rule, and §2.1 cannot treat them as a block.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §1.1
 */
#[RequiresPhpExtension('grpc')]
final class NexusServiceAndOperationNameRulesTest extends TestCase
{
    private TemporalConnection $connection;
    private WorkflowServiceClient $client;
    private OperatorServiceClient $operator;
    private string $endpointName;
    private string $endpointId = '';
    private int $endpointVersion = 0;

    /** @var list<string> */
    private array $started = [];

    protected function setUp(): void
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS not set: no Temporal server.');
        }

        $queue = 'nexus-names-' . bin2hex(random_bytes(5));
        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'nexus-names-probe',
            workflowTaskQueue: $queue,
            activityTaskQueue: $queue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
        $this->operator = new OperatorServiceClient($address, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);

        // A real endpoint: without one the command would be refused for the endpoint (cf. §1.2)
        // and we would learn nothing about the two other names.
        $this->endpointName = 'probe-names-' . bin2hex(random_bytes(4));
        $worker = new Worker();
        $worker->setNamespace($this->connection->namespace->name());
        $worker->setTaskQueue($queue);
        $target = new EndpointTarget();
        $target->setWorker($worker);
        $spec = new EndpointSpec();
        $spec->setName($this->endpointName);
        $spec->setTarget($target);
        $req = new CreateNexusEndpointRequest();
        $req->setSpec($spec);

        $created = GrpcUnary::wait($this->operator->CreateNexusEndpoint($req, [], ['timeout' => 10_000_000]));
        $endpoint = $created->getEndpoint();
        self::assertNotNull($endpoint);
        $this->endpointId = $endpoint->getId();
        $this->endpointVersion = $endpoint->getVersion();
    }

    protected function tearDown(): void
    {
        foreach ($this->started as $workflowId) {
            $req = new TerminateWorkflowExecutionRequest();
            $req->setNamespace($this->connection->namespace->name());
            $req->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $workflowId]));
            $req->setReason('fin de sonde');

            try {
                GrpcUnary::wait($this->client->TerminateWorkflowExecution($req, [], ['timeout' => 10_000_000]));
            } catch (\RuntimeException) {
            }
        }

        if ('' !== $this->endpointId) {
            $req = new DeleteNexusEndpointRequest();
            $req->setId($this->endpointId);
            $req->setVersion($this->endpointVersion);

            try {
                GrpcUnary::wait($this->operator->DeleteNexusEndpoint($req, [], ['timeout' => 10_000_000]));
            } catch (\RuntimeException) {
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function namesTheServerDoesNotGuard(): iterable
    {
        yield 'service vide' => ['', 'op'];
        yield 'service un espace' => [' ', 'op'];
        yield 'service espace en bord' => [' svc ', 'op'];
        yield 'service tabulation' => ["sv\tc", 'op'];
        yield 'service caractère de contrôle' => ["sv\x01c", 'op'];
        yield 'service barre oblique' => ['my/service', 'op'];
        yield 'opération vide' => ['svc', ''];
        yield 'opération un espace' => ['svc', ' '];
        yield 'opération accentuée' => ['svc', 'opé'];
        yield 'opération très longue' => ['svc', 'o1000'];
    }

    #[DataProvider('namesTheServerDoesNotGuard')]
    public function testTheServerAcceptsAndRecordsThemVerbatim(string $service, string $operation): void
    {
        $operation = 'o1000' === $operation ? str_repeat('o', 1000) : $operation;

        $scheduled = $this->scheduleAndReadBack($service, $operation);

        self::assertNotNull($scheduled, 'The server refused the command: it does validate these names, then.');
        self::assertSame($service, $scheduled->getService(), 'The service is not recorded verbatim.');
        self::assertSame($operation, $scheduled->getOperation(), 'The operation is not recorded verbatim.');
    }

    /**
     * Schedules the operation, then reads back the event the server drew from it.
     * Returns null if the server refused the command.
     */
    private function scheduleAndReadBack(string $service, string $operation): ?\Temporal\Api\History\V1\NexusOperationScheduledEventAttributes
    {
        $client = new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );
        $workflowId = $client->startAsync('NexusNamesProbe', [], 'names-' . bin2hex(random_bytes(5)));
        $this->started[] = $workflowId;

        $poll = new PollWorkflowTaskQueueRequest();
        $poll->setNamespace($this->connection->namespace->name());
        $poll->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $poll->setIdentity($this->connection->identity);
        $task = GrpcUnary::wait($this->client->PollWorkflowTaskQueue($poll, [], ['timeout' => 30_000_000]));

        $attrs = new ScheduleNexusOperationCommandAttributes();
        $attrs->setEndpoint($this->endpointName);
        $attrs->setService($service);
        $attrs->setOperation($operation);

        $command = new Command();
        $command->setCommandType(CommandType::COMMAND_TYPE_SCHEDULE_NEXUS_OPERATION);
        $command->setScheduleNexusOperationCommandAttributes($attrs);

        $done = new RespondWorkflowTaskCompletedRequest();
        $done->setNamespace($this->connection->namespace->name());
        $done->setTaskToken($task->getTaskToken());
        $done->setIdentity($this->connection->identity);
        $done->setCommands([$command]);

        /** @var array{0: mixed, 1: \stdClass} $pair */
        $pair = $this->client->RespondWorkflowTaskCompleted($done, [], ['timeout' => 30_000_000])->wait();
        if (0 !== (int) ($pair[1]->code ?? -1)) {
            return null;
        }

        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        foreach ($cursor->events(new WorkflowExecution(['workflow_id' => $workflowId])) as $event) {
            if (EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED === $event->getEventType()) {
                return $event->getNexusOperationScheduledEventAttributes();
            }
        }

        return null;
    }
}
