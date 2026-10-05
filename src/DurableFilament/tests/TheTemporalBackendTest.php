<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Tests;

use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Temporal\Api\Nexus\V1\EndpointSpec;
use Temporal\Api\Nexus\V1\EndpointTarget;
use Temporal\Api\Nexus\V1\EndpointTarget\Worker;
use Temporal\Api\Operatorservice\V1\CreateNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\DeleteNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\OperatorServiceClient;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;

/**
 * The panel rendered against a real Temporal server (#712): the run list, and the run page of a
 * run that has a Nexus operation in flight.
 *
 * Needs DURABLE_DSN, a server with Nexus on, the namespace of the DSN, and Durable's search
 * attributes (`DurableWorkflowName`, `DurableExecutionId`, Keyword). The test starts a run, plays
 * the workflow worker by hand to schedule one operation on an endpoint whose queue nobody polls,
 * and reads what the panel says about it.
 */
#[RequiresPhpExtension('grpc')]
final class TheTemporalBackendTest extends PanelTestCase
{
    protected const BACKEND = 'temporal';

    private static string $queue = '';
    private string $endpointId = '';
    private int $endpointVersion = 0;
    private ?OperatorServiceClient $operator = null;

    protected static function durableConfig(): array
    {
        $dsn = (string) (getenv('DURABLE_DSN') ?: '');
        if ('' === $dsn) {
            self::markTestSkipped('Set DURABLE_DSN to a Temporal server with Nexus on (temporal server start-dev).');
        }
        // A queue of its own: no other run on the namespace feeds this test's polls.
        self::$queue = 'filament-' . bin2hex(random_bytes(5));

        return [
            'backend' => 'temporal',
            'temporal' => ['dsn' => $dsn . (str_contains($dsn, '?') ? '&' : '?') . 'workflow_task_queue=' . self::$queue, 'search_attributes' => true],
        ];
    }

    protected function tearDown(): void
    {
        if (null !== $this->operator && '' !== $this->endpointId) {
            $request = new DeleteNexusEndpointRequest();
            $request->setId($this->endpointId);
            $request->setVersion($this->endpointVersion);

            try {
                GrpcUnary::wait($this->operator->DeleteNexusEndpoint($request, [], ['timeout' => 10_000_000]));
            } catch (\RuntimeException) {
            }
        }

        parent::tearDown();
    }

    public function testTheRunListAndTheRunPageShowARunWithANexusOperationInFlight(): void
    {
        $connection = $this->app->make(TemporalConnection::class);
        $client = $this->app->make(WorkflowServiceClientInterface::class);
        $executionId = 'filament-nexus-' . bin2hex(random_bytes(4));
        $endpoint = $this->createEndpoint($connection);

        $this->app->make(WorkflowClientInterface::class)->startAsync('App\\ShipWorkflow', [], ExecutionId::fromString($executionId));

        // The workflow worker, by hand: one task, one ScheduleNexusOperation command.
        $poll = new PollWorkflowTaskQueueRequest();
        $poll->setNamespace($connection->namespace->name());
        $poll->setTaskQueue(new TaskQueue(['name' => self::$queue]));
        $poll->setIdentity($connection->identity);
        $task = $client->PollWorkflowTaskQueue($poll, [], ['timeout' => 30_000_000]);
        $buffer = new TemporalWorkflowCommandBuffer($connection, ExecutionId::fromString($executionId));
        $buffer->scheduleNexusOperation(
            'op-1',
            NexusEndpoint::named($endpoint),
            NexusService::named('stock'),
            NexusOperationName::named('reserve'),
            [],
            new NexusOperationTimeouts(scheduleToClose: Duration::minutes(5)),
            NexusOperationHeaders::none(),
        );
        $done = new RespondWorkflowTaskCompletedRequest();
        $done->setNamespace($connection->namespace->name());
        $done->setTaskToken($task->getTaskToken());
        $done->setIdentity($connection->identity);
        $done->setCommands($buffer->flush());
        $client->RespondWorkflowTaskCompleted($done, [], ['timeout' => 30_000_000]);

        // Visibility is eventually consistent: the list may need a moment to carry the run.
        $list = null;
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            $list = $this->get('/admin/durable/runs');
            if (str_contains((string) $list->getContent(), $executionId)) {
                break;
            }
            usleep(500_000);
        }
        self::assertNotNull($list);
        $list->assertOk()->assertSee($executionId)->assertSee('App\\ShipWorkflow');

        $this->get('/admin/durable/run?executionId=' . $executionId)
            ->assertOk()
            ->assertSee('App\\ShipWorkflow')
            ->assertSee('Nexus operations')
            ->assertSee($endpoint)
            ->assertSee('stock')
            ->assertSee('reserve')
            ->assertSee('in flight');
    }

    /** An endpoint whose task queue nobody polls: the operation stays scheduled. */
    private function createEndpoint(TemporalConnection $connection): string
    {
        $this->operator = new OperatorServiceClient($connection->target, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);
        $name = 'filament-' . bin2hex(random_bytes(4));
        $worker = new Worker();
        $worker->setNamespace($connection->namespace->name());
        $worker->setTaskQueue(self::$queue . '-nexus');
        $target = new EndpointTarget();
        $target->setWorker($worker);
        $spec = new EndpointSpec();
        $spec->setName($name);
        $spec->setTarget($target);
        $request = new CreateNexusEndpointRequest();
        $request->setSpec($spec);
        $created = GrpcUnary::wait($this->operator->CreateNexusEndpoint($request, [], ['timeout' => 10_000_000]));
        $this->endpointId = $created->getEndpoint()?->getId() ?? '';
        $this->endpointVersion = $created->getEndpoint()?->getVersion() ?? 0;

        return $name;
    }
}
