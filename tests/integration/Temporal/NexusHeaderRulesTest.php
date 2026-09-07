<?php

declare(strict_types=1);

namespace integration\Temporal;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\MapField;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
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
 * Probe for §1.1 and §1.2 of the `nexus-operation-headers` change: what does the server accept as a
 * Nexus header, and does it return what it is given?
 *
 * The house rule is to probe before encoding the least invariant. A value object stricter than the
 * server would refuse perfectly valid headers; more lenient, it would let through what the server
 * silently rewrites — and a rewritten header can only be seen by reading a history back.
 *
 * The bridge's buffer does not send a header yet: that is the whole point of the change. The
 * command is therefore assembled by hand, as the unknown endpoint probe did.
 *
 * **Prerequisite**: a Nexus endpoint, which this test creates and deletes itself.
 *
 * @see openspec/changes/nexus-operation-headers/tasks.md §1.1 §1.2
 */
#[RequiresPhpExtension('grpc')]
final class NexusHeaderRulesTest extends TestCase
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

        $queue = 'nexus-hdr-' . bin2hex(random_bytes(5));
        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'durable-nexus-header',
            workflowTaskQueue: $queue,
            activityTaskQueue: $queue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
        $this->operator = new OperatorServiceClient($address, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);

        $this->endpointName = 'durable-hdr-' . bin2hex(random_bytes(4));
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
            $request->setReason('fin de sonde');

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

    public function testWhatTheServerKeepsVerbatim(): void
    {
        // Everything the server accepts as is. Nothing here justifies a value object being
        // stricter: refusing these cases would reject perfectly valid headers.
        foreach ([
            'ordinary' => ['x-correlation' => 'abc-123'],
            'empty value' => ['x-vide' => ''],
            'empty key' => ['' => 'valeur'],
            'whitespace at the value edges' => ['x-bord' => ' abc '],
            'newline in the value' => ['x-nl' => "a\nb"],
            'key with a space' => ['x avec espace' => 'v'],
            '1000-character value' => ['x-long' => str_repeat('a', 1000)],
            'two headers' => ['x-un' => '1', 'x-deux' => '2'],
        ] as $label => $header) {
            $expected = $header;
            ksort($expected);
            self::assertSame($expected, $this->roundTrip($header), $label);
        }
    }

    public function testTheServerLowercasesEveryKey(): void
    {
        // The silent rewrite §1.2 was looking for. A caller reading its own key back would
        // believe it had sent `X-Correlation`.
        self::assertSame(
            ['x-correlation' => 'abc-123'],
            $this->roundTrip(['X-Correlation' => 'abc-123']),
        );
        self::assertSame(['x-tout-maj' => 'v'], $this->roundTrip(['X-TOUT-MAJ' => 'v']));
    }

    public function testTwoKeysDifferingOnlyByCaseSilentlyLoseOne(): void
    {
        // The consequence, and it is the one that must govern §2.1: two headers go in, only one
        // comes out. No error, no trace — the silent breakage that this component's value objects
        // exist to make impossible.
        $back = $this->roundTrip(['X-Choc' => 'majuscule', 'x-choc' => 'minuscule']);

        self::assertCount(1, $back, 'The server kept both: the collision does not exist.');
        self::assertArrayHasKey('x-choc', $back);
    }

    /**
     * @param array<string, string> $header
     *
     * @return array<string, string>
     */
    private function roundTrip(array $header): array
    {
        $verdict = $this->probe($header);
        self::assertIsArray($verdict, \sprintf('The server refused: %s', json_encode($header)));

        return $verdict;
    }

    /** @param array<string, string> $header */
    private function probe(array $header): array|string
    {
        $client = new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );
        $this->workflowId = $client->startAsync('NexusHeaderProbe', [], 'nexushdr-' . bin2hex(random_bytes(4)));

        $poll = new PollWorkflowTaskQueueRequest();
        $poll->setNamespace($this->connection->namespace->name());
        $poll->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $poll->setIdentity($this->connection->identity);
        $task = GrpcUnary::wait($this->client->PollWorkflowTaskQueue($poll, [], ['timeout' => 30_000_000]));

        $map = new MapField(GPBType::STRING, GPBType::STRING);
        foreach ($header as $k => $v) {
            $map[$k] = $v;
        }

        $attrs = new ScheduleNexusOperationCommandAttributes();
        $attrs->setEndpoint($this->endpointName);
        $attrs->setService('billing');
        $attrs->setOperation('charge');
        $attrs->setInput(JsonPlainPayload::encode(['operationId' => 'op-1', 'payload' => []]));

        try {
            $attrs->setNexusHeader($map);
        } catch (\Throwable $e) {
            return 'refused on the protobuf side: ' . $e->getMessage();
        }

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
            return \sprintf('refused [%d]: %s', (int) $pair[1]->code, substr((string) ($pair[1]->details ?? ''), 0, 90));
        }

        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        foreach ($cursor->events(new WorkflowExecution(['workflow_id' => (string) $this->workflowId])) as $event) {
            if (EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED === $event->getEventType()) {
                $back = [];
                foreach ($event->getNexusOperationScheduledEventAttributes()?->getNexusHeader() ?? [] as $k => $v) {
                    $back[(string) $k] = (string) $v;
                }

                // The protobuf map guarantees no order: we sort before returning, failing which
                // any multiple header would look rewritten.

                ksort($back);

                return $back;
            }
        }

        return 'accepted, but no NEXUS_OPERATION_SCHEDULED';
    }
}
