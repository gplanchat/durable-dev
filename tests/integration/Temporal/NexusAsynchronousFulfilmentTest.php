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
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Command\V1\CompleteWorkflowExecutionCommandAttributes;
use Temporal\Api\Common\V1\Callback;
use Temporal\Api\Common\V1\Callback\Nexus as NexusCallback;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Nexus\V1\EndpointSpec;
use Temporal\Api\Nexus\V1\EndpointTarget;
use Temporal\Api\Nexus\V1\EndpointTarget\Worker;
use Temporal\Api\Nexus\V1\Response as NexusResponse;
use Temporal\Api\Nexus\V1\StartOperationResponse;
use Temporal\Api\Nexus\V1\StartOperationResponse\Async as StartOperationAsync;
use Temporal\Api\Operatorservice\V1\CreateNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\DeleteNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\OperatorServiceClient;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollNexusTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * §3.1 — la sonde qui tranche l'hypothèse centrale du design.
 *
 * Le design la pose et demande qu'on la falsifie :
 *
 * > le gestionnaire répond une fois, en nommant un workflow, et le serveur corrèle la complétion
 * > de ce workflow à l'opération sans que le gestionnaire soit sollicité de nouveau.
 *
 * La sonde 1.4 n'avait mesuré que la moitié : le serveur **accepte** un jeton et écrit
 * `NEXUS_OPERATION_STARTED`. Que la complétion revienne ensuite au bon endroit n'avait jamais été
 * observé — c'était une déduction tirée de `callback: temporal://system` lu dans la tâche de start.
 *
 * Ce que la sonde monte, sans aucun worker : un appelant Durable planifie l'opération ; on répond à
 * la tâche Nexus par un jeton, **après** avoir démarré un second workflow portant le `callback` de
 * la tâche dans ses `completion_callbacks` ; on termine ce workflow ; et on regarde si l'historique
 * de l'appelant reçoit `NEXUS_OPERATION_COMPLETED` avec le résultat de ce workflow.
 *
 * Si oui, l'hypothèse tient et le contrat du gestionnaire est « rends un jeton ».
 * Si non, le jeton n'est pas au gestionnaire de le choisir, et le contrat est « démarre ce
 * workflow » — la plomberie attachant le callback et dérivant le jeton.
 */
#[RequiresPhpExtension('grpc')]
final class NexusAsynchronousFulfilmentTest extends TestCase
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
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS non défini : pas de serveur Temporal.');
        }

        $queue = 'nexus-async-' . bin2hex(random_bytes(5));
        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'durable-nexus-async',
            workflowTaskQueue: $queue,
            activityTaskQueue: $queue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
        $this->operator = new OperatorServiceClient($address, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);

        $this->endpointName = 'durable-as-' . bin2hex(random_bytes(4));
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

    public function testAWorkflowCarryingTheTasksCallbackCompletesTheCallersOperation(): void
    {
        $callerId = $this->scheduleOperationFromACaller();

        $task = $this->pollNexusTask();
        $request = $task->getRequest()?->getStartOperation();
        self::assertNotNull($request, 'Aucune tâche start_operation reçue sur la file Nexus.');

        $callbackUrl = (string) $request->getCallback();
        self::assertNotSame('', $callbackUrl, 'La tâche de start ne porte aucun callback.');

        // Le workflow qui remplit l'opération, démarré AVANT la réponse : le callback ne
        // s'attache qu'au démarrage, `completion_callbacks` n'existant pas ailleurs.
        $fulfillerId = $this->startFulfillingWorkflow($callbackUrl, $request->getCallbackHeader());

        $this->respondAsynchronously($task->getTaskToken(), $fulfillerId);

        // Le gestionnaire a répondu et ne sera plus sollicité : c'est ce workflow-ci qui porte
        // désormais l'opération. On le termine, et on regarde chez l'appelant.
        $this->completeFulfillingWorkflow($fulfillerId, ['greeting' => 'hello ada']);

        $outcome = $this->awaitTerminalNexusEvent($callerId);

        self::assertSame(
            EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED,
            $outcome['type'],
            'Le serveur n’a pas corrélé la complétion du workflow à l’opération : ' . $outcome['names'],
        );
        self::assertSame(['greeting' => 'hello ada'], $outcome['result']);
    }

    private function scheduleOperationFromACaller(): string
    {
        $client = new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );
        $callerId = $client->startAsync('NexusAsyncCaller', [], 'nxasync-' . bin2hex(random_bytes(4)));
        $this->started[] = (string) $callerId;

        $task = $this->pollWorkflowTask();
        $buffer = new TemporalWorkflowCommandBuffer($this->connection, 'exec-1');
        $buffer->scheduleNexusOperation(
            'op-' . bin2hex(random_bytes(4)),
            NexusEndpoint::named($this->endpointName),
            NexusService::named('probe'),
            NexusOperationName::named('greet'),
            ['name' => 'ada'],
            new NexusOperationTimeouts(scheduleToClose: Duration::minutes(5)),
            NexusOperationHeaders::none(),
        );
        $this->respondToWorkflowTask($task, $buffer->flush());

        return (string) $callerId;
    }

    private function startFulfillingWorkflow(string $callbackUrl, mixed $callbackHeader): string
    {
        $nexus = new NexusCallback();
        $nexus->setUrl($callbackUrl);
        if (null !== $callbackHeader) {
            $nexus->setHeader($callbackHeader);
        }
        $callback = new Callback();
        $callback->setNexus($nexus);

        $workflowId = 'nxfulfil-' . bin2hex(random_bytes(4));
        $request = new StartWorkflowExecutionRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setWorkflowId($workflowId);
        $request->setWorkflowType((new WorkflowType())->setName('NexusFulfiller'));
        $request->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $request->setIdentity($this->connection->identity);
        $request->setRequestId(bin2hex(random_bytes(8)));
        $request->setCompletionCallbacks([$callback]);

        GrpcUnary::wait($this->client->StartWorkflowExecution($request, [], ['timeout' => 10_000_000]));
        $this->started[] = $workflowId;

        return $workflowId;
    }

    private function respondAsynchronously(string $taskToken, string $operationToken): void
    {
        $async = new StartOperationAsync();
        $async->setOperationToken($operationToken);
        $start = new StartOperationResponse();
        $start->setAsyncSuccess($async);
        $response = new NexusResponse();
        $response->setStartOperation($start);

        $request = new RespondNexusTaskCompletedRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setIdentity($this->connection->identity);
        $request->setTaskToken($taskToken);
        $request->setResponse($response);

        /** @var array{0: mixed, 1: \stdClass} $pair */
        $pair = $this->client->RespondNexusTaskCompleted($request, [], ['timeout' => 20_000_000])->wait();
        self::assertSame(
            0,
            (int) ($pair[1]->code ?? -1),
            'Le serveur a refusé la réponse asynchrone : ' . (string) ($pair[1]->details ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function completeFulfillingWorkflow(string $workflowId, array $result): void
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $task = $this->pollWorkflowTask();
            if ($workflowId !== (string) $task->getWorkflowExecution()?->getWorkflowId()) {
                continue;
            }

            $attributes = new CompleteWorkflowExecutionCommandAttributes();
            $attributes->setResult((new Payloads())->setPayloads([JsonPlainPayload::encode($result)]));
            $command = new Command();
            $command->setCommandType(CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION);
            $command->setCompleteWorkflowExecutionCommandAttributes($attributes);

            $this->respondToWorkflowTask($task, [$command]);

            return;
        }

        self::fail("Aucune tâche de workflow pour {$workflowId} : impossible de le terminer.");
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

        return ['type' => 0, 'result' => null, 'names' => implode(', ', $names)];
    }

    private function pollNexusTask(): \Temporal\Api\Workflowservice\V1\PollNexusTaskQueueResponse
    {
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $poll = new PollNexusTaskQueueRequest();
            $poll->setNamespace($this->connection->namespace->name());
            $poll->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
            $poll->setIdentity($this->connection->identity);

            $task = GrpcUnary::wait($this->client->PollNexusTaskQueue($poll, [], ['timeout' => 30_000_000]));
            // §1.2 : une file vide rend un jeton vide et une requête nulle. C'est un succès.
            if ('' !== (string) $task->getTaskToken()) {
                return $task;
            }
        }

        self::fail('Aucune tâche Nexus reçue.');
    }

    private function pollWorkflowTask(): PollWorkflowTaskQueueResponse
    {
        $poll = new PollWorkflowTaskQueueRequest();
        $poll->setNamespace($this->connection->namespace->name());
        $poll->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
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
