<?php

declare(strict_types=1);

namespace integration\Temporal;

use Google\Protobuf\Duration as PbDuration;
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
use Temporal\Api\History\V1\HistoryEvent;
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
 * Sonde §1.3 : les trois bornes d'une opération Nexus se comportent-elles comme celles d'une
 * activité, réécriture silencieuse comprise ?
 *
 * Rien n'a besoin de **s'exécuter** pour le savoir. Les bornes sont des attributs que le serveur
 * valide à la soumission de la commande, et l'événement `NEXUS_OPERATION_SCHEDULED` les renvoie
 * telles qu'il les a retenues. La sonde envoie donc des valeurs connues et relit ce qui a été
 * enregistré : tout écart est une réécriture.
 *
 * Aucun worker ne sert l'endpoint. C'est voulu : l'opération n'a pas à démarrer, il suffit
 * qu'elle soit planifiée.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §1.3
 */
#[RequiresPhpExtension('grpc')]
final class NexusOperationBoundsTest extends TestCase
{
    private TemporalConnection $connection;
    private WorkflowServiceClient $client;
    private OperatorServiceClient $operator;
    private ?string $workflowId = null;
    private ?string $endpointId = null;
    private string $endpointName = '';

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
        $this->createEndpoint();
    }

    protected function tearDown(): void
    {
        // Le serveur est partagé : ni exécution ouverte, ni endpoint laissé derrière soi.
        if (null !== $this->workflowId) {
            $req = new TerminateWorkflowExecutionRequest();
            $req->setNamespace($this->connection->namespace->name());
            $req->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $this->workflowId]));
            $req->setReason('fin de sonde');

            try {
                GrpcUnary::wait($this->client->TerminateWorkflowExecution($req, [], ['timeout' => 10_000_000]));
            } catch (\RuntimeException) {
            }
        }

        if (null !== $this->endpointId) {
            $del = new DeleteNexusEndpointRequest();
            $del->setId($this->endpointId);
            $del->setVersion(1);
            $this->operator->DeleteNexusEndpoint($del, [], ['timeout' => 10_000_000])->wait();
        }
    }

    public function testTheThreeBoundsAreRecordedExactlyAsSent(): void
    {
        $scheduled = $this->scheduleWith(30, 10, 20);

        self::assertNotNull($scheduled, "L'opération n'a pas été planifiée : " . $this->historyNames());
        $attrs = $scheduled->getNexusOperationScheduledEventAttributes();
        self::assertNotNull($attrs);

        self::assertSame(30, (int) $attrs->getScheduleToCloseTimeout()?->getSeconds(), 'schedule-to-close réécrit');
        self::assertSame(10, (int) $attrs->getScheduleToStartTimeout()?->getSeconds(), 'schedule-to-start réécrit');
        self::assertSame(20, (int) $attrs->getStartToCloseTimeout()?->getSeconds(), 'start-to-close réécrit');
    }

    public function testAnOperationWithNoBoundAtAllIsAcceptedAndNoDefaultIsSupplied(): void
    {
        $scheduled = $this->scheduleWith(null, null, null);

        self::assertNotNull($scheduled, "L'opération n'a pas été planifiée sans borne : " . $this->historyNames());
        $attrs = $scheduled->getNexusOperationScheduledEventAttributes();
        self::assertNotNull($attrs);

        // Verdict : le serveur n'exige **aucune** borne de fermeture et n'en invente aucune.
        // C'est la divergence avec les activités, que Temporal refuse sans borne de fermeture —
        // et donc la réponse à la condition posée en §2.2.
        self::assertNull($attrs->getScheduleToCloseTimeout());
        self::assertNull($attrs->getScheduleToStartTimeout());
        self::assertNull($attrs->getStartToCloseTimeout());
    }

    public function testABoundLongerThanTheWorkflowRunIsClampedToIt(): void
    {
        // Réécriture silencieuse n° 1, et c'est exactement le comportement des bornes d'activité :
        // le serveur rabat sur la durée de l'exécution, sans le dire. Un appelant qui relit sa
        // propre valeur croirait avoir demandé une heure.
        $scheduled = $this->scheduleWith(3600, null, null, runTimeout: 60);

        self::assertNotNull($scheduled, "L'opération n'a pas été planifiée : " . $this->historyNames());
        self::assertSame(
            60,
            (int) $scheduled->getNexusOperationScheduledEventAttributes()?->getScheduleToCloseTimeout()?->getSeconds(),
            'La borne devrait être rabattue sur la durée de run.',
        );
    }

    public function testAnIncoherentPairIsAcceptedAsIs(): void
    {
        // schedule-to-start plus long que schedule-to-close n'a aucun sens : l'opération devrait
        // expirer avant d'avoir pu démarrer. Le serveur le refuse-t-il, le réécrit-il, ou le
        // garde-t-il tel quel ?
        $scheduled = $this->scheduleWith(10, 30, null);

        self::assertNotNull($scheduled, "L'opération n'a pas été planifiée : " . $this->historyNames());
        $attrs = $scheduled->getNexusOperationScheduledEventAttributes();

        // Réécriture silencieuse n° 2 : schedule-to-start est rabattu sur schedule-to-close.
        // Le serveur ne refuse pas la paire, il la rend cohérente sans le dire.
        self::assertSame(10, (int) $attrs?->getScheduleToCloseTimeout()?->getSeconds());
        self::assertSame(10, (int) $attrs?->getScheduleToStartTimeout()?->getSeconds());
    }

    private function scheduleWith(?int $scheduleToClose, ?int $scheduleToStart, ?int $startToClose, ?int $runTimeout = null): ?HistoryEvent
    {
        $this->workflowId = $this->startWithoutWorker($runTimeout);
        $poll = $this->pollOnce();

        $attrs = new ScheduleNexusOperationCommandAttributes();
        $attrs->setEndpoint($this->endpointName);
        $attrs->setService('probe-service');
        $attrs->setOperation('probe-operation');
        if (null !== $scheduleToClose) {
            $attrs->setScheduleToCloseTimeout(new PbDuration(['seconds' => $scheduleToClose]));
        }
        if (null !== $scheduleToStart) {
            $attrs->setScheduleToStartTimeout(new PbDuration(['seconds' => $scheduleToStart]));
        }
        if (null !== $startToClose) {
            $attrs->setStartToCloseTimeout(new PbDuration(['seconds' => $startToClose]));
        }

        $command = new Command();
        $command->setCommandType(CommandType::COMMAND_TYPE_SCHEDULE_NEXUS_OPERATION);
        $command->setScheduleNexusOperationCommandAttributes($attrs);

        $req = new RespondWorkflowTaskCompletedRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setTaskToken($poll->getTaskToken());
        $req->setIdentity($this->connection->identity);
        $req->setCommands([$command]);

        /** @var array{0: mixed, 1: \stdClass} $pair */
        $pair = $this->client->RespondWorkflowTaskCompleted($req, [], ['timeout' => 30_000_000])->wait();
        $status = $pair[1];
        if (0 !== (int) ($status->code ?? -1)) {
            self::fail(\sprintf('Commande refusée [%d] : %s', (int) $status->code, (string) ($status->details ?? '')));
        }

        return $this->findEvent(EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED);
    }

    private function createEndpoint(): void
    {
        $this->endpointName = 'probe-bounds-' . bin2hex(random_bytes(6));

        $worker = new Worker();
        $worker->setNamespace($this->connection->namespace->name());
        $worker->setTaskQueue('durable-nexus-probe');

        $target = new EndpointTarget();
        $target->setWorker($worker);

        $spec = new EndpointSpec();
        $spec->setName($this->endpointName);
        $spec->setTarget($target);

        $req = new CreateNexusEndpointRequest();
        $req->setSpec($spec);

        /** @var array{0: \Temporal\Api\Operatorservice\V1\CreateNexusEndpointResponse|null, 1: \stdClass} $pair */
        $pair = $this->operator->CreateNexusEndpoint($req, [], ['timeout' => 10_000_000])->wait();
        [$resp, $status] = $pair;
        if (0 !== (int) ($status->code ?? -1)) {
            self::markTestSkipped('Endpoint Nexus non créable : ' . (string) ($status->details ?? ''));
        }
        $this->endpointId = $resp?->getEndpoint()?->getId();
    }

    private function startWithoutWorker(?int $runTimeout = null): string
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

        return $client->startAsync('NexusBoundsProbe', [], 'nexusbounds-' . bin2hex(random_bytes(4)), $options);
    }

    private function pollOnce(): PollWorkflowTaskQueueResponse
    {
        $req = new PollWorkflowTaskQueueRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $req->setIdentity($this->connection->identity);

        $resp = GrpcUnary::wait($this->client->PollWorkflowTaskQueue($req, [], ['timeout' => 30_000_000]));
        self::assertInstanceOf(PollWorkflowTaskQueueResponse::class, $resp);

        return $resp;
    }

    private function findEvent(int $type): ?HistoryEvent
    {
        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => (string) $this->workflowId]);
        foreach ($cursor->events($execution) as $event) {
            if ($event->getEventType() === $type) {
                return $event;
            }
        }

        return null;
    }

    private function historyNames(): string
    {
        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => (string) $this->workflowId]);
        $names = [];
        foreach ($cursor->events($execution) as $event) {
            $names[] = EventType::name($event->getEventType());
        }

        return implode(', ', $names);
    }
}
