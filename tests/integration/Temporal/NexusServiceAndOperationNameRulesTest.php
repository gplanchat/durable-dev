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
 * Sonde, et non fonctionnalité : seconde moitié de §1.1, celle que la sonde d'endpoint ne pouvait
 * pas atteindre. Les noms de service et d'opération voyagent dans la commande
 * `ScheduleNexusOperation`, il faut donc un endpoint qui existe et une tâche de workflow complétée
 * pour les soumettre au serveur.
 *
 * Verdict, contre Temporal 1.31.2 : **le serveur n'en valide aucun**. Vide, un espace, blancs en
 * bord, tabulation, caractère de contrôle, barre oblique, accent, mille caractères — tout est
 * accepté, et `NEXUS_OPERATION_SCHEDULED` enregistre le nom **verbatim**.
 *
 * C'est l'exact opposé de l'endpoint, dont le serveur énonce la regex et qu'il refuse à la
 * création — et c'est le mode de défaillance de {@see \Gplanchat\Durable\TaskQueue}, à la lettre :
 * accepté sans broncher, jamais servi. Une opération planifiée sur un service mal nommé attend un
 * gestionnaire qui ne correspondra jamais, sans une ligne d'erreur.
 *
 * Conséquence pour §2 : `NexusService` et `NexusOperationName` doivent être **plus stricts que le
 * serveur**, comme `TaskQueue` et à l'inverse de `NexusEndpoint`. Les trois noms d'une même
 * commande ne suivent donc pas la même règle, et §2.1 ne peut pas les traiter d'un bloc.
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
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS non défini : pas de serveur Temporal.');
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

        // Un endpoint réel : sans lui la commande serait refusée pour l'endpoint (cf. §1.2) et on
        // ne saurait rien des deux autres noms.
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

        self::assertNotNull($scheduled, 'Le serveur a refusé la commande : il valide donc ces noms.');
        self::assertSame($service, $scheduled->getService(), 'Le service n’est pas enregistré verbatim.');
        self::assertSame($operation, $scheduled->getOperation(), 'L’opération n’est pas enregistrée verbatim.');
    }

    /**
     * Planifie l'opération, puis relit l'événement que le serveur en a tiré.
     * Rend null si le serveur a refusé la commande.
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
