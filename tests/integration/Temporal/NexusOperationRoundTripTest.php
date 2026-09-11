<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
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
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\NexusOperationScheduledEventAttributes;
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

/**
 * Is the command the bridge builds accepted by a real server, and does it come back unchanged in
 * the history?
 *
 * The unit tests of `TemporalWorkflowCommandBuffer` check the SHAPE of the command. They cannot say
 * whether the server accepts it: that is what this file adds, and that is what other commands of
 * this bridge lacked — a command that is well formed but never submitted passes every test and does
 * nothing.
 *
 * **Prerequisite of the test namespace: a Nexus endpoint.** Unlike the search attributes, which
 * have to be declared by hand, this test creates its own and deletes it on the way out — an
 * endpoint name is unique for the whole cluster, and leaving one lying around would get in the way
 * of every other session. The manual equivalent, for whoever wants to reproduce it by hand:
 *
 *     temporal operator nexus endpoint create --name durable-probe --target-namespace durable-test \
 *         --target-task-queue durable-nexus
 *
 * No worker is started: the workflow task is polled and completed by the test itself, which is the
 * only way to submit a command built by the buffer without depending on the fiber driver.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §6.1 §6.2
 */
#[RequiresPhpExtension('grpc')]
final class NexusOperationRoundTripTest extends TestCase
{
    private TemporalConnection $connection;
    private WorkflowServiceClientInterface $client;
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

        $queue = 'nexus-rt-' . bin2hex(random_bytes(5));
        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'durable-nexus-roundtrip',
            workflowTaskQueue: $queue,
            activityTaskQueue: $queue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
        $this->operator = new OperatorServiceClient($address, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);

        $this->endpointName = 'durable-rt-' . bin2hex(random_bytes(4));
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
                $this->client->TerminateWorkflowExecution($request, [], ['timeout' => 10_000_000]);
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

    public function testTheCommandTheBridgeBuildsIsAcceptedAndComesBackUnchanged(): void
    {
        $scheduled = $this->scheduleThrough(new NexusOperationTimeouts(
            scheduleToClose: Duration::seconds(600),
            scheduleToStart: Duration::seconds(30),
            startToClose: Duration::seconds(120),
        ));

        self::assertSame($this->endpointName, $scheduled->getEndpoint());
        self::assertSame('billing', $scheduled->getService());
        self::assertSame('charge', $scheduled->getOperation());

        // The bounds come back as they are: none exceeds the envelope, so nothing is clamped.
        self::assertSame(600, $scheduled->getScheduleToCloseTimeout()?->getSeconds());
        self::assertSame(30, $scheduled->getScheduleToStartTimeout()?->getSeconds());
        self::assertSame(120, $scheduled->getStartToCloseTimeout()?->getSeconds());
    }

    public function testTheInputSurvivesTheRoundTrip(): void
    {
        $scheduled = $this->scheduleThrough(NexusOperationTimeouts::none());

        $input = $scheduled->getInput();
        self::assertNotNull($input, 'The operation input was not recorded.');

        // 1b.2 removed the envelope: the server receives the caller's payload, bare. Still
        // looking for a `payload` key would amount to demanding the envelope this work removed
        // — and that is exactly what a handler from another SDK would not find.
        $decoded = JsonPlainPayload::decode($input);
        self::assertSame(['amount' => 10], $decoded);
    }

    public function testUnboundedStaysUnbounded(): void
    {
        // With no bound, the server invents none (§1.3): this test is the guard of that promise
        // against a server defect that might appear one day.
        $scheduled = $this->scheduleThrough(NexusOperationTimeouts::none());

        self::assertNull($scheduled->getScheduleToCloseTimeout());
        self::assertNull($scheduled->getScheduleToStartTimeout());
        self::assertNull($scheduled->getStartToCloseTimeout());
    }

    /**
     * Builds the command through the bridge's buffer, submits it, and reads back what the history
     * kept of it.
     */
    public function testAHeaderSentThroughTheBridgeComesBackUnchanged(): void
    {
        // §4.1. The block 1 probe established that the server accepts the header; this test
        // proves that **our command** carries it all the way there. The buffer's unit tests check
        // the shape of the field — they cannot say that it survives submission.
        $scheduled = $this->scheduleThrough(
            NexusOperationTimeouts::none(),
            NexusOperationHeaders::of(['x-correlation' => 'abc-123', 'x-tenant' => 'acme']),
        );

        $back = [];
        foreach ($scheduled->getNexusHeader() as $key => $value) {
            $back[(string) $key] = (string) $value;
        }
        ksort($back);

        self::assertSame(['x-correlation' => 'abc-123', 'x-tenant' => 'acme'], $back);
    }

    public function testAKeyGivenInUpperCaseIsAlreadyLoweredBeforeItLeaves(): void
    {
        // The coercion belongs to the value object. What the server returns must therefore be
        // identical to what the caller held — not merely equivalent to what it typed.
        $headers = NexusOperationHeaders::of(['X-Correlation' => 'abc-123']);
        $scheduled = $this->scheduleThrough(NexusOperationTimeouts::none(), $headers);

        $back = [];
        foreach ($scheduled->getNexusHeader() as $key => $value) {
            $back[(string) $key] = (string) $value;
        }

        self::assertSame($headers->toArray(), $back, 'What the caller holds must be what the server keeps.');
    }

    public function testNoHeaderMeansNoHeaderInHistory(): void
    {
        $scheduled = $this->scheduleThrough(NexusOperationTimeouts::none());

        self::assertCount(0, $scheduled->getNexusHeader());
    }

    private function scheduleThrough(NexusOperationTimeouts $timeouts, ?NexusOperationHeaders $headers = null): NexusOperationScheduledEventAttributes
    {
        $client = new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );
        $this->workflowId = $client->startAsync('NexusRoundTrip', [], 'nexusrt-' . bin2hex(random_bytes(4)));

        $poll = new PollWorkflowTaskQueueRequest();
        $poll->setNamespace($this->connection->namespace->name());
        $poll->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $poll->setIdentity($this->connection->identity);
        $task = $this->client->PollWorkflowTaskQueue($poll, [], ['timeout' => 30_000_000]);

        $buffer = new TemporalWorkflowCommandBuffer($this->connection, 'exec-1');
        $buffer->scheduleNexusOperation(
            'op-' . bin2hex(random_bytes(4)),
            NexusEndpoint::named($this->endpointName),
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            ['amount' => 10],
            $timeouts,
            $headers ?? NexusOperationHeaders::none(),
        );

        $done = new RespondWorkflowTaskCompletedRequest();
        $done->setNamespace($this->connection->namespace->name());
        $done->setTaskToken($task->getTaskToken());
        $done->setIdentity($this->connection->identity);
        $done->setCommands($buffer->flush());
        $this->client->RespondWorkflowTaskCompleted($done, [], ['timeout' => 30_000_000]);

        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        foreach ($cursor->events(new WorkflowExecution(['workflow_id' => (string) $this->workflowId])) as $event) {
            if (EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED === $event->getEventType()) {
                $attributes = $event->getNexusOperationScheduledEventAttributes();
                self::assertInstanceOf(NexusOperationScheduledEventAttributes::class, $attributes);

                return $attributes;
            }
        }

        self::fail('No NEXUS_OPERATION_SCHEDULED in the history.');
    }
}
