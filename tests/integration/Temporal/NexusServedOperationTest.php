<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
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
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Command\V1\CompleteWorkflowExecutionCommandAttributes;
use Temporal\Api\Common\V1\Payloads;
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
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * §6.1 — un appelant Durable et un gestionnaire Durable, sur un vrai serveur, dans les deux formes.
 *
 * La différence avec {@see NexusAsynchronousFulfilmentTest} : là, le trajet était monté à la main
 * pour mesurer ce que fait le serveur. Ici c'est {@see TemporalNexusWorker} qui poll, route et
 * répond — donc le code qui partira en production, et non une reconstitution.
 *
 * Ce que les tests unitaires du worker ne peuvent pas prouver et qui se joue ici : que le serveur
 * **accepte** ce que le worker lui envoie. Un `syncSuccess` mal formé ou un callback mal attaché
 * passe une assertion sur un mock et se fait rejeter par le serveur.
 */
#[RequiresPhpExtension('grpc')]
final class NexusServedOperationTest extends TestCase
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

        $this->queue = 'nexus-served-' . bin2hex(random_bytes(5));
        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'durable-nexus-served',
            workflowTaskQueue: $this->queue,
            activityTaskQueue: $this->queue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
        $this->operator = new OperatorServiceClient($address, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);

        $this->endpointName = 'durable-sv-' . bin2hex(random_bytes(4));
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

    public function testAnImmediateHandlerAnswersTheCallerThroughTheWorker(): void
    {
        $registry = new NexusOperationRegistry();
        $registry->register(
            NexusService::named('probe'),
            NexusOperationName::named('greet'),
            static fn(mixed $payload): NexusOperationResponse => NexusOperationResponse::completed(
                ['greeting' => 'hello ' . ($payload['name'] ?? '?')],
            ),
        );

        $callerId = $this->scheduleOperation('greet', ['name' => 'ada']);
        $this->worker($registry)->pollOnce();

        $outcome = $this->awaitTerminalNexusEvent($callerId);

        self::assertSame(EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED, $outcome['type'], $outcome['names']);
        self::assertSame(['greeting' => 'hello ada'], $outcome['result']);
    }

    public function testADeferredHandlerAnswersWhenItsWorkflowFinishes(): void
    {
        $registry = new NexusOperationRegistry();
        $registry->register(
            NexusService::named('probe'),
            NexusOperationName::named('greet'),
            static fn(mixed $payload): NexusOperationResponse => NexusOperationResponse::fulfilledByWorkflow(
                'SlowGreeting',
                $payload,
                'served-fulfiller-' . bin2hex(random_bytes(4)),
            ),
        );

        $callerId = $this->scheduleOperation('greet', ['name' => 'ada']);
        $this->worker($registry)->pollOnce();

        // Le worker a démarré le workflow qui remplit l'opération, avec le callback attaché. Rien
        // ne le sollicitera plus : ce workflow porte l'opération, et c'est sa fin qui la règle.
        $fulfillerId = $this->completeTheFulfillingWorkflow(['greeting' => 'hello ada, plus tard']);

        $outcome = $this->awaitTerminalNexusEvent($callerId);

        self::assertNotSame('', $fulfillerId, 'Le worker n’a démarré aucun workflow.');
        self::assertSame(EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED, $outcome['type'], $outcome['names']);
        self::assertSame(['greeting' => 'hello ada, plus tard'], $outcome['result']);
    }

    public function testAnOperationNobodyServesIsRefusedAndTheServerAcceptsTheRefusal(): void
    {
        $callerId = $this->scheduleOperation('greet', []);

        // Un registre vide : le serveur doit accepter le refus typé que le worker lui envoie.
        // Si le format était mauvais, `RespondNexusTaskFailed` lèverait ici.
        $this->worker(new NexusOperationRegistry())->pollOnce();

        self::assertNotSame('', $callerId);
    }

    private function worker(NexusOperationRegistry $registry): TemporalNexusWorker
    {
        return new TemporalNexusWorker(
            new WorkflowServiceNexusRpc($this->client),
            $this->connection,
            $registry,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function scheduleOperation(string $operation, array $payload): string
    {
        $client = new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );
        $callerId = (string) $client->startAsync('NexusServedCaller', [], 'nxserved-' . bin2hex(random_bytes(4)));
        $this->started[] = $callerId;

        $task = $this->pollWorkflowTask();
        $buffer = new TemporalWorkflowCommandBuffer($this->connection, 'exec-1');
        $buffer->scheduleNexusOperation(
            'op-' . bin2hex(random_bytes(4)),
            NexusEndpoint::named($this->endpointName),
            NexusService::named('probe'),
            NexusOperationName::named($operation),
            $payload,
            new NexusOperationTimeouts(scheduleToClose: Duration::minutes(5)),
            NexusOperationHeaders::none(),
        );
        $this->respondToWorkflowTask($task, $buffer->flush());

        return $callerId;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function completeTheFulfillingWorkflow(array $result): string
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $task = $this->pollWorkflowTask();
            $workflowId = (string) $task->getWorkflowExecution()?->getWorkflowId();
            if (!str_starts_with($workflowId, 'served-fulfiller-')) {
                continue;
            }
            $this->started[] = $workflowId;

            $attributes = new CompleteWorkflowExecutionCommandAttributes();
            $attributes->setResult((new Payloads())->setPayloads([JsonPlainPayload::encode($result)]));
            $command = new Command();
            $command->setCommandType(CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION);
            $command->setCompleteWorkflowExecutionCommandAttributes($attributes);

            $this->respondToWorkflowTask($task, [$command]);

            return $workflowId;
        }

        return '';
    }

    /**
     * @return array{type: int, result: mixed, names: string}
     */
    private function awaitTerminalNexusEvent(string $callerId): array
    {
        $terminal = [
            EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED,
            EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED,
            EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT,
            EventType::EVENT_TYPE_NEXUS_OPERATION_CANCELED,
        ];

        $names = [];
        for ($attempt = 0; $attempt < 60; ++$attempt) {
            $names = [];
            $cursor = new TemporalHistoryCursor($this->client, $this->connection);
            foreach ($cursor->events(new WorkflowExecution(['workflow_id' => $callerId])) as $event) {
                $type = (int) $event->getEventType();
                $names[] = (string) $type;
                if (!\in_array($type, $terminal, true)) {
                    continue;
                }

                $result = null;
                if (EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED === $type) {
                    $payload = $event->getNexusOperationCompletedEventAttributes()?->getResult();
                    $result = null === $payload ? null : JsonPlainPayload::decode($payload);
                }

                return ['type' => $type, 'result' => $result, 'names' => implode(', ', $names)];
            }
            usleep(500_000);
        }

        return ['type' => 0, 'result' => null, 'names' => 'aucun événement terminal : ' . implode(', ', $names)];
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
     * @param list<Command> $commands
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
