<?php

declare(strict_types=1);

namespace App\Tests\Integration\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput;
use Gplanchat\Bridge\Temporal\Interpreter\JournalActivityInterpreter;
use Gplanchat\Bridge\Temporal\Journal\HistoryPageMerger;
use Gplanchat\Bridge\Temporal\Journal\JournalWorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\TemporalJournalGrpcPoller;
use Gplanchat\Bridge\Temporal\TemporalStartingEventStore;
use Gplanchat\Bridge\Temporal\TemporalWorkflowStarter;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Flux minimal : EventStore partagé + interpréteur → commande ScheduleActivity sur un vrai Temporal (DUR026).
 *
 * @see DURABLE_DSN — ajouter &interpreter_mirror=1 pour JournalActivityInterpreter côté processor.
 */
#[Group('temporal-integration')]
final class TemporalInterpreterMirrorIntegrationTest extends TestCase
{
    private static TemporalConnection $connection;

    private static WorkflowServiceClient $client;

    public static function setUpBeforeClass(): void
    {
        $dsn = (string) (getenv('DURABLE_DSN') ?: '');
        if ('' === trim($dsn)) {
            self::markTestSkipped(
                'Définissez DURABLE_DSN (ex. temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0&interpreter_mirror=1).',
            );
        }
        if (!extension_loaded('grpc')) {
            self::markTestSkipped('L’extension PHP grpc est requise.');
        }

        self::$connection = TemporalConnection::fromDsn($dsn);
        if (!self::temporalTcpReachable(self::$connection)) {
            self::markTestSkipped('Temporal n’est pas joignable sur '.self::$connection->target.'.');
        }

        self::$client = WorkflowServiceClientFactory::create(self::$connection);
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

    public function testJournalPollWithInterpreterEmitsScheduleActivityCommand(): void
    {
        $executionId = 'mirror-integ-'.uniqid('', true);
        $store = new TemporalStartingEventStore(
            new InMemoryEventStore(),
            new TemporalWorkflowStarter(self::$client, self::$connection, new WorkflowDefinitionLoader()),
        );

        $store->append(new ExecutionStarted($executionId, ['workflowType' => 'Integration_Mirror']));
        $scheduled = new ActivityScheduled($executionId, 'act-mirror-1', 'IntegrationEcho', ['ping' => 1], []);
        $store->append($scheduled);

        $merger = new HistoryPageMerger(self::$client, self::$connection->namespace);
        $interpreter = new JournalActivityInterpreter(self::$connection);
        $processor = new JournalWorkflowTaskProcessor(self::$client, self::$connection, $merger, $store, $interpreter);

        $poller = new TemporalJournalGrpcPoller(self::$client, self::$connection);

        $processed = false;
        for ($i = 0; $i < 80; ++$i) {
            $poll = $poller->pollOnce();
            if ('' !== $poll->getTaskToken()) {
                $processor->process($poll);
                $processed = true;
                break;
            }
            usleep(100_000);
        }

        self::assertTrue($processed, 'Aucun workflow task reçu sur la file journal (worker journal démarré ?).');
    }

    public function testTemporalActivityScheduleInputRoundTrip(): void
    {
        $scheduled = new ActivityScheduled('e1', 'a1', 'T', ['x' => 2], ['k' => 'v']);
        $row = TemporalActivityScheduleInput::encodeFromScheduled($scheduled);
        self::assertSame('e1', $row['executionId']);
        self::assertSame('a1', $row['activityId']);
        self::assertSame('T', $row['activityName']);
        self::assertSame(['x' => 2], $row['payload']);

        $payloads = JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($row));
        self::assertNotEmpty($payloads);
    }
}
