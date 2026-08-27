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
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * La commande que le pont construit est-elle acceptée par un vrai serveur, et revient-elle
 * inchangée dans l'historique ?
 *
 * Les tests unitaires de `TemporalWorkflowCommandBuffer` vérifient la FORME de la commande. Ils ne
 * peuvent pas dire si le serveur l'accepte : c'est ce que ce fichier ajoute, et c'est ce qui a
 * manqué à d'autres commandes de ce pont — une commande bien formée mais jamais soumise passe tous
 * les tests et ne fait rien.
 *
 * **Prérequis du namespace de test : un endpoint Nexus.** Contrairement aux attributs de recherche,
 * qui doivent être déclarés à la main, ce test crée le sien et le supprime en sortant — un nom
 * d'endpoint est unique pour le cluster entier, et en laisser traîner gênerait toute autre session.
 * L'équivalent manuel, pour qui veut reproduire à la main :
 *
 *     temporal operator nexus endpoint create --name durable-probe --target-namespace durable-test \
 *         --target-task-queue durable-nexus
 *
 * Aucun worker n'est démarré : la tâche de workflow est poll et complétée par le test lui-même,
 * ce qui est le seul moyen de soumettre une commande construite par le tampon sans dépendre du
 * pilote de fiber.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §6.1 §6.2
 */
#[RequiresPhpExtension('grpc')]
final class NexusOperationRoundTripTest extends TestCase
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

        // Les bornes reviennent telles quelles : aucune n'excède l'enveloppe, donc rien n'est raboté.
        self::assertSame(600, $scheduled->getScheduleToCloseTimeout()?->getSeconds());
        self::assertSame(30, $scheduled->getScheduleToStartTimeout()?->getSeconds());
        self::assertSame(120, $scheduled->getStartToCloseTimeout()?->getSeconds());
    }

    public function testTheInputSurvivesTheRoundTrip(): void
    {
        $scheduled = $this->scheduleThrough(NexusOperationTimeouts::none());

        $input = $scheduled->getInput();
        self::assertNotNull($input, 'L’entrée de l’opération n’a pas été enregistrée.');

        $decoded = JsonPlainPayload::decode($input);
        self::assertIsArray($decoded);
        self::assertSame(['amount' => 10], $decoded['payload'] ?? null);
    }

    public function testUnboundedStaysUnbounded(): void
    {
        // Sans borne, le serveur n'en invente aucune (§1.3) : ce test est la garde de cette
        // promesse contre un défaut serveur qui apparaîtrait un jour.
        $scheduled = $this->scheduleThrough(NexusOperationTimeouts::none());

        self::assertNull($scheduled->getScheduleToCloseTimeout());
        self::assertNull($scheduled->getScheduleToStartTimeout());
        self::assertNull($scheduled->getStartToCloseTimeout());
    }

    /**
     * Construit la commande par le tampon du pont, la soumet, et relit ce que l'historique en a
     * gardé.
     */
    private function scheduleThrough(NexusOperationTimeouts $timeouts): NexusOperationScheduledEventAttributes
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
        $task = GrpcUnary::wait($this->client->PollWorkflowTaskQueue($poll, [], ['timeout' => 30_000_000]));

        $buffer = new TemporalWorkflowCommandBuffer($this->connection, 'exec-1');
        $buffer->scheduleNexusOperation(
            'op-' . bin2hex(random_bytes(4)),
            NexusEndpoint::named($this->endpointName),
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            ['amount' => 10],
            $timeouts,
            NexusOperationHeaders::none(),
        );

        $done = new RespondWorkflowTaskCompletedRequest();
        $done->setNamespace($this->connection->namespace->name());
        $done->setTaskToken($task->getTaskToken());
        $done->setIdentity($this->connection->identity);
        $done->setCommands($buffer->flush());

        /** @var array{0: mixed, 1: \stdClass} $pair */
        $pair = $this->client->RespondWorkflowTaskCompleted($done, [], ['timeout' => 30_000_000])->wait();
        self::assertSame(
            0,
            (int) ($pair[1]->code ?? -1),
            \sprintf('Le serveur a refusé la commande du pont : %s', (string) ($pair[1]->details ?? '')),
        );

        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        foreach ($cursor->events(new WorkflowExecution(['workflow_id' => (string) $this->workflowId])) as $event) {
            if (EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED === $event->getEventType()) {
                $attributes = $event->getNexusOperationScheduledEventAttributes();
                self::assertInstanceOf(NexusOperationScheduledEventAttributes::class, $attributes);

                return $attributes;
            }
        }

        self::fail('Aucun NEXUS_OPERATION_SCHEDULED dans l’historique.');
    }
}
