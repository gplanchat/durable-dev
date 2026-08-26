<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Durable\WorkflowStartOptions;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Socle des tests exécutés contre un **vrai** serveur Temporal.
 *
 * Le driver n'était vérifié qu'au niveau protobuf : des commandes bien formées, jamais soumises
 * à un serveur. Ici elles doivent être acceptées.
 *
 *     temporal server start-dev --namespace durable-test --port 7233
 *     DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
 *
 * Ignoré si l'adresse n'est pas fournie.
 *
 * @requires extension grpc
 */
abstract class TemporalServerTestCase extends TestCase
{
    protected TemporalConnection $connection;
    protected WorkflowServiceClient $client;

    /** @var list<resource> */
    private array $workers = [];

    /** @var list<array{role: string, pipes: array<int, resource>}> */
    private array $workerPipes = [];

    protected function setUp(): void
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS non défini : pas de serveur Temporal.');
        }

        // Une file par test : les workers d'un cas ne volent pas les tâches d'un autre.
        $taskQueue = 'durable-it-'.bin2hex(random_bytes(6));

        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            identity: 'durable-integration',
            workflowTaskQueue: $taskQueue,
            activityTaskQueue: $taskQueue,
        );
        $this->client = WorkflowServiceClientFactory::create($this->connection);

        $this->spawnWorker('workflow');
        $this->spawnWorker('activity');
    }

    protected function tearDown(): void
    {
        // Sans ça, un worker qui meurt au démarrage se manifeste par un simple timeout muet.
        if (!$this->status()->isSuccess()) {
            fwrite(\STDERR, $this->workerOutput());
        }

        foreach ($this->workerPipes as $worker) {
            foreach ($worker['pipes'] as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
        foreach ($this->workers as $process) {
            if (\is_resource($process)) {
                proc_terminate($process, \SIGKILL);
                proc_close($process);
            }
        }
        $this->workers = [];
        $this->workerPipes = [];
    }

    protected function workflowClient(): WorkflowClient
    {
        return new WorkflowClient(
            $this->client,
            $this->connection,
            new TemporalHistoryCursor($this->client, $this->connection),
            new WorkflowServiceExecutionRpc($this->client),
        );
    }

    /**
     * Démarre le workflow et rend son résultat, ou échoue avec le message porté par l'historique.
     *
     * @param array<string, mixed> $input
     */
    protected function runWorkflow(string $workflowType, array $input, float $timeoutSeconds = 30.0): mixed
    {
        $executionId = strtolower($workflowType).'-'.bin2hex(random_bytes(4));
        $this->workflowClient()->startAsync($workflowType, $input, $executionId);

        return $this->workflowClient()->pollForCompletion($executionId, 250, (int) ($timeoutSeconds * 4));
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function startWorkflow(string $workflowType, array $input, ?WorkflowStartOptions $options = null): string
    {
        $executionId = strtolower($workflowType).'-'.bin2hex(random_bytes(4));
        $this->workflowClient()->startAsync($workflowType, $input, $executionId, $options);

        return $executionId;
    }

    protected function workflowId(string $executionId): string
    {
        return $this->workflowClient()->workflowId($executionId);
    }

    /**
     * Attend qu'un événement du type donné apparaisse dans l'historique, et le rend.
     */
    protected function waitForHistoryEvent(string $executionId, int $eventType, float $timeoutSeconds = 30.0): HistoryEvent
    {
        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => $this->workflowId($executionId)]);
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            foreach ($cursor->events($execution) as $event) {
                if ($event->getEventType() === $eventType) {
                    return $event;
                }
            }
            usleep(250_000);
        }

        self::fail(\sprintf(
            'Événement %s absent de l’historique de "%s" après %.0f s : %s',
            EventType::name($eventType),
            $executionId,
            $timeoutSeconds,
            implode(', ', $this->historyEventNames($executionId)),
        ));
    }

    /** @return list<string> */
    protected function historyEventNames(string $executionId): array
    {
        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        $execution = new WorkflowExecution(['workflow_id' => $this->workflowId($executionId)]);

        $names = [];
        foreach ($cursor->events($execution) as $event) {
            $names[] = EventType::name($event->getEventType());
        }

        return $names;
    }

    protected function workerOutput(): string
    {
        $out = '';
        foreach ($this->workerPipes as $worker) {
            foreach ($worker['pipes'] as $fd => $pipe) {
                if (!\is_resource($pipe)) {
                    continue;
                }
                $text = trim((string) stream_get_contents($pipe));
                if ('' !== $text) {
                    $out .= \sprintf("[worker %s fd%d]\n%s\n", $worker['role'], $fd, $text);
                }
            }
        }

        return $out;
    }

    private function spawnWorker(string $role): void
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [
                \PHP_BINARY,
                __DIR__.'/worker.php',
                $this->connection->target,
                $this->connection->namespace->name(),
                $this->connection->workflowTaskQueue,
                $role,
            ],
            $descriptors,
            $pipes,
        );

        if (!\is_resource($process)) {
            self::fail(\sprintf('Impossible de démarrer le worker %s.', $role));
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $this->workerPipes[] = ['role' => $role, 'pipes' => $pipes];
        $this->workers[] = $process;
    }
}
