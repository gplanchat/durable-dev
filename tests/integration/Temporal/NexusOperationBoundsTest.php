<?php

declare(strict_types=1);

namespace integration\Temporal;

use Google\Protobuf\Duration;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Command\V1\ScheduleNexusOperationCommandAttributes;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\CommandType;
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
 * A probe, not a feature: §1.3 asks whether the three bounds of a Nexus operation behave like an
 * activity's, **silent rewrites included**. There is one, and that is the result that counts here.
 *
 * Measured against Temporal 1.31.2:
 *
 * - a negative duration is refused on each of the three, and the message NAMES the faulty field;
 * - a sub-bound larger than `scheduleToClose` is **clamped down to its value, without a word**:
 *   asking for 60 s of `startToClose` under 10 s of `scheduleToClose` records 10 s;
 * - `scheduleToClose = 0` clamps nothing: it means "no bound", not "zero seconds";
 * - an omitted bound stays absent from the event — the server invents none.
 *
 * What this imposes on `NexusOperationTimeouts`: make the rewrite visible at construction rather
 * than letting it happen on the server side. A value object that accepts 60/10 and lets the user
 * believe in 60 reproduces exactly the class of faults `ActivityTimeouts` was written to make
 * impossible.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §1.3
 */
#[RequiresPhpExtension('grpc')]
final class NexusOperationBoundsTest extends TestCase
{
    private const GRPC_INVALID_ARGUMENT = 3;

    private TemporalConnection $connection;
    private WorkflowServiceClientInterface $client;
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

        $queue = 'nexus-bounds-' . bin2hex(random_bytes(5));
        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'nexus-bounds-probe',
            workflowTaskQueue: $queue,
            activityTaskQueue: $queue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
        $this->operator = new OperatorServiceClient($address, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);

        $this->endpointName = 'probe-bounds-' . bin2hex(random_bytes(4));
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

        $created = $this->operator->CreateNexusEndpoint($req, [], ['timeout' => 10_000_000]);
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
                $this->client->TerminateWorkflowExecution($req, [], ['timeout' => 10_000_000]);
            } catch (\RuntimeException) {
            }
        }

        if ('' !== $this->endpointId) {
            $req = new DeleteNexusEndpointRequest();
            $req->setId($this->endpointId);
            $req->setVersion($this->endpointVersion);

            try {
                $this->operator->DeleteNexusEndpoint($req, [], ['timeout' => 10_000_000]);
            } catch (\RuntimeException) {
            }
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function eachBound(): iterable
    {
        yield 'scheduleToClose' => [0, 'ScheduleToCloseTimeout'];
        yield 'scheduleToStart' => [1, 'ScheduleToStartTimeout'];
        yield 'startToClose' => [2, 'StartToCloseTimeout'];
    }

    #[DataProvider('eachBound')]
    public function testANegativeDurationIsRefusedAndTheFieldIsNamed(int $index, string $field): void
    {
        $bounds = [null, null, null];
        $bounds[$index] = -5;

        $error = $this->schedule(...$bounds);

        self::assertIsString($error, 'A negative duration was accepted.');
        self::assertStringContainsString('negative duration', $error);
        self::assertStringContainsString($field, $error, 'The message does not name the faulty bound.');
    }

    public function testASubBoundLargerThanScheduleToCloseIsSilentlyRewrittenDownToIt(): void
    {
        // The core of §1.3: asking for more than the envelope and getting it clamped, no error.
        $scheduled = $this->schedule(10, 60, 60);

        self::assertInstanceOf(NexusOperationScheduledEventAttributes::class, $scheduled);
        self::assertSame(10, $scheduled->getScheduleToCloseTimeout()?->getSeconds());
        self::assertSame(
            10,
            $scheduled->getScheduleToStartTimeout()?->getSeconds(),
            'scheduleToStart was not clamped down to scheduleToClose.',
        );
        self::assertSame(
            10,
            $scheduled->getStartToCloseTimeout()?->getSeconds(),
            'startToClose was not clamped down to scheduleToClose.',
        );
    }

    public function testAZeroScheduleToCloseMeansUnboundedAndRewritesNothing(): void
    {
        $scheduled = $this->schedule(0, 30, null);

        self::assertInstanceOf(NexusOperationScheduledEventAttributes::class, $scheduled);
        self::assertSame(0, $scheduled->getScheduleToCloseTimeout()?->getSeconds());
        self::assertSame(
            30,
            $scheduled->getScheduleToStartTimeout()?->getSeconds(),
            'Zero was treated as a zero-second envelope and clamped everything.',
        );
    }

    public function testOmittedBoundsStayAbsent(): void
    {
        $scheduled = $this->schedule(null, null, null);

        self::assertInstanceOf(NexusOperationScheduledEventAttributes::class, $scheduled);
        self::assertNull($scheduled->getScheduleToCloseTimeout());
        self::assertNull($scheduled->getScheduleToStartTimeout());
        self::assertNull($scheduled->getStartToCloseTimeout());
    }

    /**
     * Schedules the operation and reads its event back.
     * Returns the recorded attributes, or the server's message if it refused.
     */
    public function testTheWorkflowRunIsASecondOuterEnvelopeThatAlsoClampsSilently(): void
    {
        // `scheduleToClose` is the envelope of the three bounds, but there is a **second** one on
        // top of it: the duration of the execution itself. A caller can therefore compose a set of
        // bounds perfectly consistent with each other and still have them trimmed, without error.
        // That is the question §1.3 asked — "like the activities?" — and the answer is yes there
        // too.
        $attrs = $this->schedule(3600, null, null, runTimeout: 60);

        self::assertInstanceOf(NexusOperationScheduledEventAttributes::class, $attrs);
        self::assertSame(
            60,
            (int) $attrs->getScheduleToCloseTimeout()?->getSeconds(),
            'One hour asked for under an execution bounded to one minute should be brought down.',
        );
    }

    private function schedule(?int $scheduleToClose, ?int $scheduleToStart, ?int $startToClose, ?int $runTimeout = null): NexusOperationScheduledEventAttributes|string|null
    {
        $client = new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );
        $options = null === $runTimeout ? null : new \Gplanchat\Durable\WorkflowStartOptions(
            timeouts: new \Gplanchat\Durable\WorkflowTimeouts(run: \Gplanchat\Durable\Duration::seconds((float) $runTimeout)),
        );
        $workflowId = $client->startAsync('NexusBoundsProbe', [], 'bounds-' . bin2hex(random_bytes(5)), $options);
        $this->started[] = $workflowId;

        $poll = new PollWorkflowTaskQueueRequest();
        $poll->setNamespace($this->connection->namespace->name());
        $poll->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $poll->setIdentity($this->connection->identity);
        $task = $this->client->PollWorkflowTaskQueue($poll, [], ['timeout' => 30_000_000]);

        $attrs = new ScheduleNexusOperationCommandAttributes();
        $attrs->setEndpoint($this->endpointName);
        $attrs->setService('svc');
        $attrs->setOperation('op');
        if (null !== $scheduleToClose) {
            $attrs->setScheduleToCloseTimeout((new Duration())->setSeconds($scheduleToClose));
        }
        if (null !== $scheduleToStart) {
            $attrs->setScheduleToStartTimeout((new Duration())->setSeconds($scheduleToStart));
        }
        if (null !== $startToClose) {
            $attrs->setStartToCloseTimeout((new Duration())->setSeconds($startToClose));
        }

        $command = new Command();
        $command->setCommandType(CommandType::COMMAND_TYPE_SCHEDULE_NEXUS_OPERATION);
        $command->setScheduleNexusOperationCommandAttributes($attrs);

        $done = new RespondWorkflowTaskCompletedRequest();
        $done->setNamespace($this->connection->namespace->name());
        $done->setTaskToken($task->getTaskToken());
        $done->setIdentity($this->connection->identity);
        $done->setCommands([$command]);

        try {
            $this->client->RespondWorkflowTaskCompleted($done, [], ['timeout' => 30_000_000]);
        } catch (\RuntimeException $e) {
            self::assertSame(self::GRPC_INVALID_ARGUMENT, $e->getCode(), 'Refused for a reason other than an invalid argument.');

            return $e->getMessage();
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
