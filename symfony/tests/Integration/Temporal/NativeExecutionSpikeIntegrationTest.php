<?php

declare(strict_types=1);

namespace App\Tests\Integration\Temporal;

use Gplanchat\Bridge\Temporal\Journal\HistoryPageMerger;
use Gplanchat\Bridge\Temporal\Spike\NativeExecutionSpike;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Vérifie que le spike DUR024 produit un historique avec événements d’activité (pas seulement des signaux).
 *
 * @see NativeExecutionSpike
 */
#[Group('temporal-native-spike')]
final class NativeExecutionSpikeIntegrationTest extends TestCase
{
    private static TemporalConnection $connection;

    private static WorkflowServiceClient $workflowClient;

    public static function setUpBeforeClass(): void
    {
        $dsn = (string) (getenv('DURABLE_DSN') ?: '');
        if ('' === trim($dsn)) {
            self::markTestSkipped(
                'Définissez DURABLE_DSN (ex. temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&tls=0).',
            );
        }
        if (!extension_loaded('grpc')) {
            self::markTestSkipped('L’extension PHP grpc est requise.');
        }

        self::$connection = TemporalConnection::fromDsn($dsn);
        if (!self::temporalTcpReachable(self::$connection)) {
            self::markTestSkipped(
                'Temporal n’est pas joignable sur '.self::$connection->target.'. Lancez `docker compose up -d` depuis `symfony/`.',
            );
        }

        self::$workflowClient = WorkflowServiceClientFactory::create(self::$connection);
    }

    public function testHistoryContainsActivityEvents(): void
    {
        $workflowId = 'durable-native-spike-test-'.Uuid::v4()->toRfc4122();
        $spike = NativeExecutionSpike::create(self::$connection);
        $runId = $spike->run($workflowId);

        self::assertNotSame('', $runId);

        $merger = new HistoryPageMerger(self::$workflowClient, self::$connection->namespace);
        $exec = new WorkflowExecution();
        $exec->setWorkflowId($workflowId);
        $exec->setRunId($runId);

        $eventCount = 0;
        for ($attempt = 0; $attempt < 80; ++$attempt) {
            $history = $merger->fullHistoryForExecution($exec);
            $types = [];
            foreach ($history->getEvents() as $event) {
                $types[] = (int) $event->getEventType();
            }
            $eventCount = \count($types);
            if (\in_array((int) EventType::EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED, $types, true) && $eventCount >= 8) {
                break;
            }
            usleep(100_000);
        }

        self::assertContains((int) EventType::EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED, $types, 'Spike must close the workflow execution.');
        self::assertGreaterThanOrEqual(
            8,
            $eventCount,
            'Native path must persist full history (activity + workflow completion).',
        );
    }

    private static function temporalTcpReachable(TemporalConnection $connection): bool
    {
        $parts = explode(':', $connection->target, 2);
        $host = $parts[0] ?? '127.0.0.1';
        $port = isset($parts[1]) ? (int) $parts[1] : 7233;

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            \sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            1.0,
            \STREAM_CLIENT_CONNECT,
        );

        if (\is_resource($fp)) {
            fclose($fp);

            return true;
        }

        return false;
    }
}
