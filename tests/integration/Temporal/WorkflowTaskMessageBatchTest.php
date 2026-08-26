<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalGrpcTimeouts;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Sonde, et non fonctionnalité : le change « workflow-conditions-and-handler-dispatch » fait de
 * l'entrelacement — appliquer un message, puis réévaluer les conditions pendantes — le cœur de sa
 * boucle (§4.2). Cette boucle n'a de sens que si un **unique** workflow task peut transporter
 * plusieurs messages journalisés : sinon l'ordre serait imposé par le serveur, une tâche par
 * message, et il n'y aurait rien à entrelacer côté domaine.
 *
 * La propriété se MESURE contre un vrai serveur et ne se déduit pas des protos : ce que
 * `PollWorkflowTaskQueueResponse` sait représenter ne dit pas ce que le serveur émet.
 *
 * ⚠ Ce que la sonde établit est que le régime groupé est **atteignable**, pas qu'il soit garanti :
 * la même sonde avec un worker en écoute rend un signal par tâche, celui-ci réclamant chaque tâche
 * avant l'arrivée du suivant. Le nombre de messages par tâche est un artefact de disponibilité du
 * worker, non un contrat — et c'est précisément ce qui interdit d'ordonner les messages par
 * frontière de tâche. Voir la section « probed » du design.
 *
 * Aucun worker n'est démarré ici, à dessein — c'est ce qui laisse les signaux s'accumuler sur la
 * tâche en attente. Le test poll la file lui-même et lit le lot que le serveur lui rend.
 *
 * @see openspec/changes/workflow-conditions-and-handler-dispatch/tasks.md §1.2
 * @see openspec/changes/workflow-conditions-and-handler-dispatch/design.md
 */
#[RequiresPhpExtension('grpc')]
final class WorkflowTaskMessageBatchTest extends TestCase
{
    private const SIGNAL_COUNT = 3;

    private TemporalConnection $connection;
    private WorkflowServiceClient $client;
    private ?string $workflowId = null;

    protected function setUp(): void
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS non défini : pas de serveur Temporal.');
        }

        $taskQueue = 'durable-probe-' . bin2hex(random_bytes(6));

        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'durable-probe-1-2',
            workflowTaskQueue: $taskQueue,
            activityTaskQueue: $taskQueue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);
    }

    protected function tearDown(): void
    {
        if (null === $this->workflowId) {
            return;
        }

        // L'exécution n'a aucun worker : sans terminaison elle resterait ouverte sur le serveur.
        $req = new TerminateWorkflowExecutionRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $this->workflowId]));
        $req->setReason('fin de sonde');
        $req->setIdentity($this->connection->identity);

        try {
            GrpcUnary::wait($this->client->TerminateWorkflowExecution($req, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US]));
        } catch (\RuntimeException) {
            // Le nettoyage ne doit pas masquer le verdict du test.
        }
    }

    public function testOneWorkflowTaskCarriesSeveralSignals(): void
    {
        $this->workflowId = $this->startWithoutWorker();

        // Première tâche : le démarrage. On la complète sans commande, pour que l'exécution reste
        // ouverte et qu'aucune tâche ne soit en vol quand les signaux arrivent.
        $first = $this->pollOnce();
        self::assertNotSame('', $first->getTaskToken(), 'Aucune tâche de workflow rendue pour le démarrage.');
        $this->completeWithoutCommands($first);

        for ($i = 0; $i < self::SIGNAL_COUNT; ++$i) {
            $this->signal('probe-' . $i, ['rang' => $i]);
        }

        $second = $this->pollOnce();
        self::assertNotSame('', $second->getTaskToken(), 'Aucune tâche de workflow rendue après les signaux.');

        // Le poll rend l'historique COMPLET : compter les signaux sur tout le lot prouverait
        // seulement qu'il y en a eu plusieurs depuis le début, pas qu'UNE tâche les porte tous.
        // Seul compte le segment que cette tâche doit traiter — ce qui suit le dernier
        // WORKFLOW_TASK_COMPLETED.
        $segment = $this->pendingSegment($second);
        $signalled = $this->countIn($segment, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED);
        $started = $this->countIn($segment, EventType::EVENT_TYPE_WORKFLOW_TASK_STARTED);

        self::assertSame(
            1,
            $started,
            'Le segment en attente porte plusieurs tâches : la mesure ne dirait plus ce qu’UNE tâche transporte.',
        );
        self::assertSame(
            self::SIGNAL_COUNT,
            $signalled,
            \sprintf(
                'Une tâche de workflow n’a pas transporté les %d messages : %d dans son segment. '
                . "L'entrelacement serait alors imposé par le serveur, une tâche par message, et §4.2 n’aurait "
                . 'plus d’objet. Segment : %s',
                self::SIGNAL_COUNT,
                $signalled,
                implode(', ', array_map(EventType::name(...), $segment)),
            ),
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

        // Le type n'a pas à exister : le serveur journalise le démarrage sans rien exécuter tant
        // qu'aucun worker ne poll — ce qui est précisément la situation voulue.
        return $client->startAsync('ProbeMessageBatch', [], 'probe-' . bin2hex(random_bytes(4)));
    }

    private function pollOnce(): PollWorkflowTaskQueueResponse
    {
        $req = new PollWorkflowTaskQueueRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $req->setIdentity($this->connection->identity);

        $resp = GrpcUnary::wait($this->client->PollWorkflowTaskQueue($req, [], ['timeout' => TemporalGrpcTimeouts::LONG_POLL_US]));
        self::assertInstanceOf(PollWorkflowTaskQueueResponse::class, $resp);

        return $resp;
    }

    private function completeWithoutCommands(PollWorkflowTaskQueueResponse $poll): void
    {
        $req = new RespondWorkflowTaskCompletedRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setTaskToken($poll->getTaskToken());
        $req->setIdentity($this->connection->identity);

        GrpcUnary::wait($this->client->RespondWorkflowTaskCompleted($req, [], ['timeout' => TemporalGrpcTimeouts::RESPOND_WORKFLOW_TASK_US]));
    }

    /** @param array<string, mixed> $args */
    private function signal(string $name, array $args): void
    {
        $req = new \Temporal\Api\Workflowservice\V1\SignalWorkflowExecutionRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setWorkflowExecution(new WorkflowExecution(['workflow_id' => (string) $this->workflowId]));
        $req->setSignalName($name);
        $req->setIdentity($this->connection->identity);
        $req->setInput(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($args)));

        GrpcUnary::wait($this->client->SignalWorkflowExecution($req, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US]));
    }

    /**
     * Les types d'événements qui suivent le dernier WORKFLOW_TASK_COMPLETED : ce que cette tâche
     * a à traiter, par opposition à l'historique déjà traité que le poll rend aussi.
     *
     * @return list<int>
     */
    private function pendingSegment(PollWorkflowTaskQueueResponse $poll): array
    {
        $segment = [];
        foreach ($poll->getHistory()?->getEvents() ?? [] as $event) {
            if (EventType::EVENT_TYPE_WORKFLOW_TASK_COMPLETED === $event->getEventType()) {
                $segment = [];

                continue;
            }
            $segment[] = $event->getEventType();
        }

        return $segment;
    }

    /** @param list<int> $segment */
    private function countIn(array $segment, int $eventType): int
    {
        return \count(array_filter($segment, static fn(int $type): bool => $type === $eventType));
    }
}
