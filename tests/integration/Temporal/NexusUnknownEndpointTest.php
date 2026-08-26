<?php

declare(strict_types=1);

namespace integration\Temporal;

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
use Temporal\Api\Enums\V1\WorkflowTaskFailedCause;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Sonde, et non fonctionnalité : le change « temporal-nexus-support » promet que les échecs d'une
 * opération Nexus remontent au workflow en échecs typés. Un endpoint inconnu **n'en fait pas
 * partie**, et c'est ce que cette sonde établit.
 *
 * Mesuré contre Temporal 1.31.2 : la commande est refusée à `RespondWorkflowTaskCompleted` avec
 * INVALID_ARGUMENT, l'historique enregistre un WORKFLOW_TASK_FAILED de cause
 * BAD_SCHEDULE_NEXUS_OPERATION_ATTRIBUTES, et la tâche est **re-servie**, son `attempt` montant à
 * chaque tour. Aucune opération Nexus n'est jamais planifiée, donc aucun échec d'opération ne peut
 * être livré : le workflow ne tombe pas, il tourne.
 *
 * Conséquence de conception : un objet-valeur `NexusEndpoint` n'y peut rien — le nom est bien
 * formé, c'est l'endpoint qui n'existe pas. Seule une vérification avant émission de la commande,
 * ou l'acceptation assumée de la boucle, couvre ce cas.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §1.2
 */
#[RequiresPhpExtension('grpc')]
final class NexusUnknownEndpointTest extends TestCase
{
    private const GRPC_INVALID_ARGUMENT = 3;

    private TemporalConnection $connection;
    private WorkflowServiceClient $client;
    private ?string $workflowId = null;

    protected function setUp(): void
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS non défini : pas de serveur Temporal.');
        }

        $queue = 'nexus-unknown-' . bin2hex(random_bytes(5));
        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'nexus-unknown-probe',
            workflowTaskQueue: $queue,
            activityTaskQueue: $queue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
    }

    protected function tearDown(): void
    {
        if (null === $this->workflowId) {
            return;
        }

        // Sans worker, l'exécution resterait ouverte à retenter sa tâche indéfiniment.
        $req = new TerminateWorkflowExecutionRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $this->workflowId]));
        $req->setReason('fin de sonde');

        try {
            GrpcUnary::wait($this->client->TerminateWorkflowExecution($req, [], ['timeout' => 10_000_000]));
        } catch (\RuntimeException) {
            // Le nettoyage ne masque pas le verdict.
        }
    }

    public function testSchedulingOnAnUnknownEndpointFailsTheWorkflowTaskAndKeepsRetrying(): void
    {
        $this->workflowId = $this->startWithoutWorker();
        $first = $this->pollOnce();

        $error = $this->respondWithNexusCommand($first);

        self::assertNotNull($error, 'Le serveur a accepté un endpoint inconnu.');
        self::assertSame(self::GRPC_INVALID_ARGUMENT, $error['code']);
        self::assertStringContainsString('BadScheduleNexusOperationAttributes', $error['message']);
        self::assertStringContainsString('not found', $error['message']);

        // L'historique porte l'échec, avec sa cause nommée.
        $failed = $this->findEvent(EventType::EVENT_TYPE_WORKFLOW_TASK_FAILED);
        self::assertNotNull($failed, 'Aucun WORKFLOW_TASK_FAILED enregistré.');
        self::assertSame(
            WorkflowTaskFailedCause::WORKFLOW_TASK_FAILED_CAUSE_BAD_SCHEDULE_NEXUS_OPERATION_ATTRIBUTES,
            $failed->getWorkflowTaskFailedEventAttributes()?->getCause(),
        );

        // Et la tâche revient : c'est une boucle, pas un arrêt. Le workflow ne tombe jamais.
        $retry = $this->pollOnce();
        self::assertNotSame('', $retry->getTaskToken(), 'La tâche n’a pas été re-servie.');
        self::assertGreaterThan(
            $first->getAttempt(),
            $retry->getAttempt(),
            'Le compteur de tentative n’a pas monté : ce ne serait pas la même tâche retentée.',
        );

        // Aucune opération Nexus n'a été planifiée — donc aucun échec d'opération à typer.
        self::assertNull(
            $this->findEvent(EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED),
            'Une opération Nexus a été planifiée alors que l’endpoint est inconnu.',
        );
    }

    private function startWithoutWorker(): string
    {
        $client = new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );

        return $client->startAsync('NexusUnknownEndpointProbe', [], 'nexusunknown-' . bin2hex(random_bytes(4)));
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

    /** @return array{code: int, message: string}|null */
    private function respondWithNexusCommand(PollWorkflowTaskQueueResponse $poll): ?array
    {
        $attrs = new ScheduleNexusOperationCommandAttributes();
        // Un nom BIEN FORMÉ au regard de la regex serveur : ce qui manque est l'endpoint lui-même.
        $attrs->setEndpoint('absent-endpoint-' . bin2hex(random_bytes(4)));
        $attrs->setService('un-service');
        $attrs->setOperation('une-operation');

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
        $code = (int) ($status->code ?? -1);

        return 0 === $code ? null : ['code' => $code, 'message' => (string) ($status->details ?? '')];
    }

    private function findEvent(int $eventType): ?\Temporal\Api\History\V1\HistoryEvent
    {
        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => (string) $this->workflowId]);

        foreach ($cursor->events($execution) as $event) {
            if ($event->getEventType() === $eventType) {
                return $event;
            }
        }

        return null;
    }
}
