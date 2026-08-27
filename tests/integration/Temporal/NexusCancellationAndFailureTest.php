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
 * §6.3 et §6.4 — l'annulation atteint le serveur, et un échec dit d'où il vient.
 *
 * Même montage que {@see NexusOperationRoundTripTest} : le test crée son propre endpoint Nexus,
 * le supprime en sortant, et pilote lui-même les tâches de workflow — aucun worker ne tourne.
 *
 * Ce que ces deux cas ajoutent au round-trip : l'annulation exige l'`eventId` **réel** de la
 * planification, qu'aucun test unitaire ne peut valider puisque c'est le serveur qui rejette un
 * identifiant inventé ; et l'échec doit remonter typé, avec son site d'appel, jusqu'au workflow.
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
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS non défini : pas de serveur Temporal.');
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

        // Un signal force une nouvelle tâche : sans worker servant l'endpoint, rien d'autre ne
        // relancerait l'exécution, et il n'y aurait pas de tâche où poser l'annulation.
        $this->signal();
        $task = $this->pollTask();

        // Le tampon relit l'historique de CETTE tâche : c'est de là que sort l'eventId réel, et
        // un identifiant inventé ferait rejeter la tâche entière par le serveur.
        $history = TemporalExecutionHistory::fromEvents(
            (new TemporalHistoryCursor($this->client, $this->connection))->eventsFromPoll($task),
        );
        $buffer = new TemporalWorkflowCommandBuffer($this->connection, 'exec-1', $history);
        $buffer->cancelNexusOperation($operationId, 'race_superseded');
        $commands = $buffer->flush();
        self::assertCount(1, $commands, "Le tampon n'a pas retrouvé l'opération dans l'historique.");

        $this->respond($task, $commands);

        self::assertTrue(
            $this->historyHas(EventType::EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUESTED),
            'Le serveur a accepté la commande sans enregistrer la demande d’annulation : '
            . implode(', ', $this->historyNames()),
        );
    }

    public function testATimedOutOperationSurfacesTypedWithItsOrigin(): void
    {
        // Une borne d'une seconde sur un endpoint que personne ne sert : le serveur finit par
        // écrire NEXUS_OPERATION_TIMED_OUT, et c'est le seul échec qu'on puisse provoquer sans
        // handler. Ce que le test vérifie est en aval — que la lecture le rende typé.
        $operationId = 'op-' . bin2hex(random_bytes(4));
        $this->scheduleOperation($operationId, new NexusOperationTimeouts(scheduleToClose: Duration::seconds(1.0)));

        $deadline = microtime(true) + 45.0;
        while (microtime(true) < $deadline && !$this->historyHas(EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT)) {
            usleep(500_000);
        }
        self::assertTrue(
            $this->historyHas(EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT),
            'Le serveur n’a pas fait expirer l’opération : ' . implode(', ', $this->historyNames()),
        );

        $history = TemporalExecutionHistory::fromEvents(
            (new TemporalHistoryCursor($this->client, $this->connection))
                ->events(new WorkflowExecution(['workflow_id' => (string) $this->workflowId])),
        );
        $slot = $history->findNexusOperationSlotResult(0);
        self::assertNotNull($slot);

        $failure = $slot['failed'];
        self::assertInstanceOf(DurableNexusOperationFailedException::class, $failure, "L'échec doit être typé, pas nu.");
        self::assertSame(NexusOperationFailureKind::Timeout, $failure->kind());
        // Le spec l'exige : un échec non rattrapé doit nommer le site d'appel.
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
        self::assertNotSame('', $task->getTaskToken(), 'Aucune tâche de workflow servie.');

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
            \sprintf('Le serveur a refusé la commande : %s', (string) ($pair[1]->details ?? '')),
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
