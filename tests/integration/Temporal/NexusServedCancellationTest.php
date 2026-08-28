<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
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
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * §4 — l'annulation, mesurée puis servie.
 *
 * §1.5 avait établi la moitié négative : avec la tâche de start encore en attente, annuler
 * l'appelant écrit `NEXUS_OPERATION_CANCEL_REQUESTED` de son côté et **aucune tâche n'arrive** au
 * gestionnaire. L'opération n'avait jamais démarré : rien à annuler chez lui.
 *
 * La moitié positive n'avait jamais pu être observée, faute de pouvoir démarrer une opération en
 * asynchrone. C'est maintenant possible, et les deux tests ici se lisent dans l'ordre : le premier
 * mesure ce que porte la tâche d'annulation — elle **nomme le jeton rendu au démarrage** —, le
 * second fait faire le geste au worker et vérifie qu'il atteint le workflow qui porte l'opération.
 */
#[RequiresPhpExtension('grpc')]
final class NexusServedCancellationTest extends TestCase
{
    private TemporalConnection $connection;
    private WorkflowServiceClient $client;
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
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS non défini : pas de serveur Temporal.');
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

        $created = GrpcUnary::wait($this->operator->CreateNexusEndpoint($request, [], ['timeout' => 10_000_000]));
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

        // L'opération a démarré : l'appelant doit voir NEXUS_OPERATION_STARTED avant qu'annuler
        // ait un sens. C'est exactement la condition que §1.5 avait trouvée manquante.
        self::assertTrue(
            $this->awaitEvent($callerId, \Temporal\Api\Enums\V1\EventType::EVENT_TYPE_NEXUS_OPERATION_STARTED),
            'L’opération n’a pas démarré : la sonde ne teste pas ce qu’elle croit.',
        );

        $this->requestCancellationFromTheCaller($callerId);

        $task = $this->pollNexusTask();
        $cancel = $task?->getRequest()?->getCancelOperation();

        self::assertNotNull($cancel, 'Aucune tâche cancel_operation n’est arrivée pour une opération démarrée.');
        self::assertSame('probe', $cancel->getService());
        self::assertSame('slow', $cancel->getOperation());
        self::assertSame(
            $fulfillerId,
            $cancel->getOperationToken(),
            'La tâche d’annulation doit nommer le jeton rendu au démarrage — c’est la seule prise sur ce qui porte l’opération.',
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
            'L’opération n’a pas démarré.',
        );
        self::assertFalse(
            $this->awaitEvent($fulfillerId, \Temporal\Api\Enums\V1\EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCEL_REQUESTED, 2),
            'Le workflow ne devait pas encore être annulé.',
        );

        $this->requestCancellationFromTheCaller($callerId);

        // Le même worker, sur la tâche d'annulation cette fois. `pollOnce()` est un poll et un
        // seul : sur une file vide il rend la main sans rien dire (§1.2), et la tâche
        // d'annulation ne s'y présente pas forcément au premier appel.
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $worker->pollOnce();
            if ($this->awaitEvent($fulfillerId, \Temporal\Api\Enums\V1\EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCEL_REQUESTED, 2)) {
                break;
            }
        }

        self::assertTrue(
            $this->awaitEvent($fulfillerId, \Temporal\Api\Enums\V1\EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCEL_REQUESTED),
            'Annuler l’opération doit annuler le workflow qui la porte.',
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
        // Un signal force une nouvelle tâche : sans lui, rien ne relancerait l'exécution et il n'y
        // aurait pas de tâche où poser l'annulation.
        $signal = new \Temporal\Api\Workflowservice\V1\SignalWorkflowExecutionRequest();
        $signal->setNamespace($this->connection->namespace->name());
        $signal->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $callerId]));
        $signal->setSignalName('reveille');
        $signal->setIdentity($this->connection->identity);
        GrpcUnary::wait($this->client->SignalWorkflowExecution($signal, [], ['timeout' => 10_000_000]));

        $task = $this->pollWorkflowTaskFor($callerId);
        // L'historique complet, et non celui de la tâche : la page que le poll rend après un
        // signal ne repart pas du début, et la planification de l'opération est en amont. Le
        // tampon n'a besoin que de l'eventId, qui est le même dans les deux lectures.
        $history = TemporalExecutionHistory::fromEvents(
            (new TemporalHistoryCursor($this->client, $this->connection))
                ->events(new WorkflowExecution(['workflow_id' => $callerId])),
        );
        $buffer = new TemporalWorkflowCommandBuffer($this->connection, 'exec-1', $history);
        $identity = $history->findScheduledNexusOperation(0);
        self::assertNotNull($identity, 'L’opération planifiée est absente de l’historique de la tâche.');
        $buffer->cancelNexusOperation($identity, 'race_superseded');
        $commands = $buffer->flush();
        self::assertCount(1, $commands, 'Le tampon n’a pas produit la commande d’annulation.');
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

            $task = GrpcUnary::wait($this->client->PollNexusTaskQueue($poll, [], ['timeout' => 30_000_000]));
            if ('' !== (string) $task->getTaskToken()) {
                return $task;
            }
        }

        return null;
    }

    /**
     * Le workflow qui remplit l'opération tourne sur la **même file** que l'appelant : un poll nu
     * peut rendre sa tâche. Répondre à celle-là avec une commande qui parle de l'historique de
     * l'appelant fait rejeter la tâche entière — le serveur dit alors que l'opération est
     * « non-existing », ce qui envoie chercher un défaut là où il n'y en a pas.
     */
    private function pollWorkflowTaskFor(string $workflowId): PollWorkflowTaskQueueResponse
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $task = $this->pollWorkflowTask();
            if ($workflowId === (string) $task->getWorkflowExecution()?->getWorkflowId()) {
                return $task;
            }
        }

        self::fail("Aucune tâche de workflow pour {$workflowId}.");
    }

    private function pollWorkflowTask(): PollWorkflowTaskQueueResponse
    {
        $poll = new PollWorkflowTaskQueueRequest();
        $poll->setNamespace($this->connection->namespace->name());
        $poll->setTaskQueue(new TaskQueue(['name' => $this->queue]));
        $poll->setIdentity($this->connection->identity);

        return GrpcUnary::wait($this->client->PollWorkflowTaskQueue($poll, [], ['timeout' => 30_000_000]));
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

        /** @var array{0: mixed, 1: \stdClass} $pair */
        $pair = $this->client->RespondWorkflowTaskCompleted($done, [], ['timeout' => 30_000_000])->wait();
        self::assertSame(
            0,
            (int) ($pair[1]->code ?? -1),
            'Le serveur a refusé la tâche de workflow : ' . (string) ($pair[1]->details ?? ''),
        );
    }
}
