<?php

declare(strict_types=1);

namespace integration\Temporal;

use Google\Protobuf\Duration;
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
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Sonde, et non fonctionnalité : §1.3 demande si les trois bornes d'une opération Nexus se
 * comportent comme celles d'une activité, **réécritures silencieuses comprises**. Il y en a une, et
 * c'est le résultat qui compte ici.
 *
 * Mesuré contre Temporal 1.31.2 :
 *
 * - une durée négative est refusée sur chacune des trois, et le message NOMME le champ fautif ;
 * - une sous-borne plus grande que `scheduleToClose` est **rabotée à sa valeur, sans un mot** :
 *   demander 60 s de `startToClose` sous 10 s de `scheduleToClose` fait enregistrer 10 s ;
 * - `scheduleToClose = 0` ne rabote rien : c'est « pas de borne », pas « zéro seconde » ;
 * - une borne omise reste absente de l'événement — le serveur n'en invente pas.
 *
 * Ce que cela impose à `NexusOperationTimeouts` : rendre la réécriture visible à la construction
 * plutôt que la laisser se produire côté serveur. Un objet-valeur qui accepte 60/10 et laisse
 * l'utilisateur croire à 60 reproduit exactement la classe de fautes que `ActivityTimeouts` a été
 * écrite pour rendre impossible.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §1.3
 */
#[RequiresPhpExtension('grpc')]
final class NexusOperationBoundsTest extends TestCase
{
    private const GRPC_INVALID_ARGUMENT = 3;

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

        self::assertIsString($error, 'Une durée négative a été acceptée.');
        self::assertStringContainsString('negative duration', $error);
        self::assertStringContainsString($field, $error, 'Le message ne nomme pas la borne fautive.');
    }

    public function testASubBoundLargerThanScheduleToCloseIsSilentlyRewrittenDownToIt(): void
    {
        // Le cœur de §1.3 : demander plus que l'enveloppe et l'obtenir rabotée, sans erreur.
        $scheduled = $this->schedule(10, 60, 60);

        self::assertInstanceOf(NexusOperationScheduledEventAttributes::class, $scheduled);
        self::assertSame(10, $scheduled->getScheduleToCloseTimeout()?->getSeconds());
        self::assertSame(
            10,
            $scheduled->getScheduleToStartTimeout()?->getSeconds(),
            'scheduleToStart n’a pas été raboté à scheduleToClose.',
        );
        self::assertSame(
            10,
            $scheduled->getStartToCloseTimeout()?->getSeconds(),
            'startToClose n’a pas été raboté à scheduleToClose.',
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
            'Zéro a été traité comme une enveloppe de zéro seconde et a tout raboté.',
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
     * Planifie l'opération et relit son événement.
     * Rend les attributs enregistrés, ou le message du serveur s'il a refusé.
     */
    private function schedule(?int $scheduleToClose, ?int $scheduleToStart, ?int $startToClose): NexusOperationScheduledEventAttributes|string|null
    {
        $client = new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );
        $workflowId = $client->startAsync('NexusBoundsProbe', [], 'bounds-' . bin2hex(random_bytes(5)));
        $this->started[] = $workflowId;

        $poll = new PollWorkflowTaskQueueRequest();
        $poll->setNamespace($this->connection->namespace->name());
        $poll->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $poll->setIdentity($this->connection->identity);
        $task = GrpcUnary::wait($this->client->PollWorkflowTaskQueue($poll, [], ['timeout' => 30_000_000]));

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

        /** @var array{0: mixed, 1: \stdClass} $pair */
        $pair = $this->client->RespondWorkflowTaskCompleted($done, [], ['timeout' => 30_000_000])->wait();
        $code = (int) ($pair[1]->code ?? -1);
        if (0 !== $code) {
            self::assertSame(self::GRPC_INVALID_ARGUMENT, $code, 'Refus pour un autre motif qu’un argument invalide.');

            return (string) ($pair[1]->details ?? '');
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
