<?php

declare(strict_types=1);

namespace integration\Temporal;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\MapField;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
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
 * Sonde §1.1 et §1.2 du change `nexus-operation-headers` : que le serveur accepte-t-il comme
 * en-tête Nexus, et rend-il ce qu'on lui donne ?
 *
 * La règle de la maison veut qu'on sonde avant d'encoder le moindre invariant. Un objet-valeur
 * plus strict que le serveur refuserait des en-têtes parfaitement valides ; plus laxiste, il
 * laisserait passer ce que le serveur réécrit en silence — et un en-tête réécrit ne se voit
 * qu'en relisant un historique.
 *
 * Le tampon du pont n'envoie pas encore d'en-tête : c'est tout l'objet du change. La commande est
 * donc assemblée à la main, comme l'a fait la sonde de l'endpoint inconnu.
 *
 * **Prérequis** : un endpoint Nexus, que ce test crée et supprime lui-même.
 *
 * @see openspec/changes/nexus-operation-headers/tasks.md §1.1 §1.2
 */
#[RequiresPhpExtension('grpc')]
final class NexusHeaderRulesTest extends TestCase
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

        $queue = 'nexus-hdr-' . bin2hex(random_bytes(5));
        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'durable-nexus-header',
            workflowTaskQueue: $queue,
            activityTaskQueue: $queue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
        $this->operator = new OperatorServiceClient($address, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);

        $this->endpointName = 'durable-hdr-' . bin2hex(random_bytes(4));
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
            $request->setReason('fin de sonde');
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

    public function testWhatTheServerKeepsVerbatim(): void
    {
        // Tout ce que le serveur accepte tel quel. Rien ici ne justifie qu'un objet-valeur soit
        // plus strict : refuser ces cas rejetterait des en-têtes parfaitement valides.
        foreach ([
            'ordinaire' => ['x-correlation' => 'abc-123'],
            'valeur vide' => ['x-vide' => ''],
            'clé vide' => ['' => 'valeur'],
            'blanc en bord de valeur' => ['x-bord' => ' abc '],
            'saut de ligne dans la valeur' => ['x-nl' => "a\nb"],
            'clé avec espace' => ['x avec espace' => 'v'],
            'valeur de 1000 caractères' => ['x-long' => str_repeat('a', 1000)],
            'deux en-têtes' => ['x-un' => '1', 'x-deux' => '2'],
        ] as $label => $header) {
            $expected = $header;
            ksort($expected);
            self::assertSame($expected, $this->roundTrip($header), $label);
        }
    }

    public function testTheServerLowercasesEveryKey(): void
    {
        // La réécriture silencieuse que §1.2 cherchait. Un appelant qui relit sa propre clé
        // croirait avoir envoyé `X-Correlation`.
        self::assertSame(
            ['x-correlation' => 'abc-123'],
            $this->roundTrip(['X-Correlation' => 'abc-123']),
        );
        self::assertSame(['x-tout-maj' => 'v'], $this->roundTrip(['X-TOUT-MAJ' => 'v']));
    }

    public function testTwoKeysDifferingOnlyByCaseSilentlyLoseOne(): void
    {
        // La conséquence, et c'est elle qui doit gouverner §2.1 : deux en-têtes entrent, un seul
        // sort. Aucune erreur, aucune trace — la panne muette que les objets-valeurs de ce
        // composant existent pour rendre impossible.
        $back = $this->roundTrip(['X-Choc' => 'majuscule', 'x-choc' => 'minuscule']);

        self::assertCount(1, $back, 'Le serveur a gardé les deux : la collision n’existe pas.');
        self::assertArrayHasKey('x-choc', $back);
    }

    /**
     * @param array<string, string> $header
     *
     * @return array<string, string>
     */
    private function roundTrip(array $header): array
    {
        $verdict = $this->probe($header);
        self::assertIsArray($verdict, \sprintf('Le serveur a refusé : %s', json_encode($header)));

        return $verdict;
    }

    /** @param array<string, string> $header */
    private function probe(array $header): array|string
    {
        $client = new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );
        $this->workflowId = $client->startAsync('NexusHeaderProbe', [], 'nexushdr-' . bin2hex(random_bytes(4)));

        $poll = new PollWorkflowTaskQueueRequest();
        $poll->setNamespace($this->connection->namespace->name());
        $poll->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $poll->setIdentity($this->connection->identity);
        $task = GrpcUnary::wait($this->client->PollWorkflowTaskQueue($poll, [], ['timeout' => 30_000_000]));

        $map = new MapField(GPBType::STRING, GPBType::STRING);
        foreach ($header as $k => $v) {
            $map[$k] = $v;
        }

        $attrs = new ScheduleNexusOperationCommandAttributes();
        $attrs->setEndpoint($this->endpointName);
        $attrs->setService('billing');
        $attrs->setOperation('charge');
        $attrs->setInput(JsonPlainPayload::encode(['operationId' => 'op-1', 'payload' => []]));
        try {
            $attrs->setNexusHeader($map);
        } catch (\Throwable $e) {
            return 'refusé côté protobuf : ' . $e->getMessage();
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
        if (0 !== (int) ($pair[1]->code ?? -1)) {
            return \sprintf('refusé [%d] : %s', (int) $pair[1]->code, substr((string) ($pair[1]->details ?? ''), 0, 90));
        }

        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        foreach ($cursor->events(new WorkflowExecution(['workflow_id' => (string) $this->workflowId])) as $event) {
            if (EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED === $event->getEventType()) {
                $back = [];
                foreach ($event->getNexusOperationScheduledEventAttributes()?->getNexusHeader() ?? [] as $k => $v) {
                    $back[(string) $k] = (string) $v;
                }

                // La map protobuf ne garantit pas l'ordre : on trie avant de rendre, sans quoi
                // tout en-tête multiple paraîtrait réécrit.

                ksort($back);

                return $back;
            }
        }

        return 'accepté, mais aucun NEXUS_OPERATION_SCHEDULED';
    }
}
