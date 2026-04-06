<?php

declare(strict_types=1);

namespace App\Tests\Integration\Temporal;

use App\Samples\Workflow\SimpleActivity\SimpleActivityGreetingWorkflow;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalGrpcTimeouts;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\TemporalNativeBootstrap;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Bridge\Temporal\Journal\JournalTemporalHistoryReader;
use Gplanchat\Bridge\Temporal\TemporalStartingEventStore;
use Gplanchat\Bridge\Temporal\TemporalWorkflowStarter;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionResponse;
use Temporal\Api\History\V1\History;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryRequest;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Tests contre un serveur Temporal réel (gRPC). Sans variable d’environnement, la suite est ignorée.
 *
 * Prérequis : `docker compose up -d` depuis `symfony/`, ext-grpc, puis par exemple :
 *
 *   DURABLE_DSN='temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&tls=0' php bin/phpunit --group temporal-integration
 */
#[Group('temporal-integration')]
final class TemporalJournalEventStoreIntegrationTest extends TestCase
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
            self::markTestSkipped('L’extension PHP grpc est requise pour parler au serveur Temporal.');
        }

        self::$connection = TemporalConnection::fromDsn($dsn);
        if (!self::temporalTcpReachable(self::$connection)) {
            self::markTestSkipped(
                'Temporal n’est pas joignable sur '.self::$connection->target.'. Lancez `docker compose up -d` depuis `symfony/`.',
            );
        }

        self::$workflowClient = WorkflowServiceClientFactory::create(self::$connection);
    }

    /**
     * Évite des erreurs gRPC peu lisibles quand le DSN est défini mais le serveur arrêté.
     */
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

    public function testStartWorkflowExecutionUsesBusinessWorkflowTypeFromExecutionStartedPayload(): void
    {
        $executionId = 'integ-wftype-'.uniqid('', true);
        $businessType = 'IntegrationTest_BusinessWorkflowType';

        $store = $this->createStore();
        $store->append(new ExecutionStarted($executionId, ['workflowType' => $businessType]));

        $wfId = self::$connection->journalWorkflowId($executionId);
        $name = $this->describeWorkflowTypeName($wfId);

        self::assertSame($businessType, $name);
    }

    public function testStartWorkflowExecutionResolvesFqcnToAliasForTemporalWorkflowType(): void
    {
        $executionId = 'integ-fqcn-'.uniqid('', true);
        $expectedAlias = (new WorkflowDefinitionLoader())->workflowTypeForClass(SimpleActivityGreetingWorkflow::class);

        $store = $this->createStore();
        $store->append(new ExecutionStarted($executionId, ['workflowType' => SimpleActivityGreetingWorkflow::class]));

        $wfId = self::$connection->journalWorkflowId($executionId);
        self::assertSame($expectedAlias, $this->describeWorkflowTypeName($wfId));
    }

    public function testStartWorkflowHistoryCarriesTemporalNativeBootstrapInMemoAndReconstructsJournalRow(): void
    {
        $executionId = 'integ-bootstrap-'.uniqid('', true);
        $store = $this->createStore();
        $payload = TemporalNativeBootstrap::withSchedule(
            ['workflowType' => 'IntegrationTest_NativeBootstrap'],
            TemporalNativeBootstrap::scheduleShape('act-bootstrap', 'IntegrationEcho', ['ping' => 1], []),
        );
        $store->append(new ExecutionStarted($executionId, $payload));

        $wfId = self::$connection->journalWorkflowId($executionId);
        $row = $this->firstWorkflowStartedJournalRow($wfId);
        $history = $this->workflowExecutionHistory($wfId);
        $attr = $this->firstWorkflowExecutionStartedAttributes($history);
        self::assertNotNull($attr);
        $decodedInput = JsonPlainPayload::decodePayloads($attr->getInput());
        self::assertCount(1, $decodedInput);
        self::assertSame([], $decodedInput[0]);
        $memo = $attr->getMemo();
        self::assertNotNull($memo);
        self::assertTrue($memo->getFields()->offsetExists(JournalExecutionIdResolver::MEMO_KEY_JOURNAL_BOOTSTRAP));

        self::assertSame(ExecutionStarted::class, $row['event_type'] ?? null);
        $inner = $row['payload'] ?? [];
        self::assertIsArray($inner);
        self::assertArrayHasKey(TemporalNativeBootstrap::PAYLOAD_KEY_SCHEDULE, $inner);
        self::assertSame(
            'act-bootstrap',
            $inner[TemporalNativeBootstrap::PAYLOAD_KEY_SCHEDULE]['activityId'] ?? null,
        );
    }

    public function testStartWorkflowExecutionWorkflowInputIsBusinessDataNotJournalEnvelope(): void
    {
        $executionId = 'integ-business-input-'.uniqid('', true);
        $store = $this->createStore();
        $store->append(new ExecutionStarted($executionId, [
            'workflowType' => 'IntegrationTest_BusinessInput',
            'name' => 'Temporal',
            'count' => 2,
        ]));

        $wfId = self::$connection->journalWorkflowId($executionId);
        $history = $this->workflowExecutionHistory($wfId);
        $attr = $this->firstWorkflowExecutionStartedAttributes($history);
        self::assertNotNull($attr);
        $decoded = JsonPlainPayload::decodePayloads($attr->getInput());
        self::assertCount(1, $decoded);
        self::assertIsArray($decoded[0]);
        self::assertSame(['name' => 'Temporal', 'count' => 2], $decoded[0]);
        self::assertArrayNotHasKey('event_type', $decoded[0]);
    }

    public function testAppendTwoEventsAndReadStreamRoundTrip(): void
    {
        $executionId = 'integ-read-'.uniqid('', true);
        $store = $this->createStore();

        $store->append(new ExecutionStarted($executionId, ['workflowType' => 'IntegrationTest_ReadStream']));
        $store->append(new ExecutionCompleted($executionId, ['ok' => true]));

        self::assertSame(2, $store->countEventsInStream($executionId));

        $events = iterator_to_array($store->readStream($executionId), false);
        self::assertCount(2, $events);
        self::assertInstanceOf(ExecutionStarted::class, $events[0]);
        self::assertInstanceOf(ExecutionCompleted::class, $events[1]);
    }

    private function createStore(): TemporalStartingEventStore
    {
        return new TemporalStartingEventStore(
            new InMemoryEventStore(),
            new TemporalWorkflowStarter(
                self::$workflowClient,
                self::$connection,
                new WorkflowDefinitionLoader(),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function firstWorkflowStartedJournalRow(string $workflowId): array
    {
        $history = $this->workflowExecutionHistory($workflowId);
        $rows = JournalTemporalHistoryReader::journalRowsFromHistory($history);
        self::assertNotEmpty($rows, 'JournalTemporalHistoryReader must reconstruct at least one row');

        return $rows[0];
    }

    private function workflowExecutionHistory(string $workflowId): History
    {
        $req = new GetWorkflowExecutionHistoryRequest();
        $req->setNamespace(self::$connection->namespace);
        $exec = new WorkflowExecution();
        $exec->setWorkflowId($workflowId);
        $req->setExecution($exec);
        $req->setMaximumPageSize(50);
        $call = self::$workflowClient->GetWorkflowExecutionHistory($req, [], ['timeout' => TemporalGrpcTimeouts::HISTORY_US]);
        $response = GrpcUnary::wait($call);
        self::assertInstanceOf(GetWorkflowExecutionHistoryResponse::class, $response);
        $history = $response->getHistory();
        self::assertNotNull($history);

        return $history;
    }

    private function firstWorkflowExecutionStartedAttributes(History $history): ?\Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes
    {
        foreach ($history->getEvents() as $event) {
            if (EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED !== $event->getEventType()) {
                continue;
            }

            return $event->getWorkflowExecutionStartedEventAttributes();
        }

        return null;
    }

    private function describeWorkflowTypeName(string $workflowId): string
    {
        $req = new DescribeWorkflowExecutionRequest();
        $req->setNamespace(self::$connection->namespace);
        $exec = new WorkflowExecution();
        $exec->setWorkflowId($workflowId);
        $exec->setRunId('');
        $req->setExecution($exec);

        $call = self::$workflowClient->DescribeWorkflowExecution($req);
        /** @var array{0: object|null, 1: \stdClass} $pair */
        $pair = $call->wait();
        [$response, $status] = $pair;
        $code = (int) ($status->code ?? -1);
        self::assertSame(0, $code, 'DescribeWorkflowExecution doit réussir : '.(string) ($status->details ?? ''));

        self::assertInstanceOf(DescribeWorkflowExecutionResponse::class, $response);
        $info = $response->getWorkflowExecutionInfo();
        self::assertNotNull($info);
        $type = $info->getType();
        self::assertNotNull($type);

        return (string) $type->getName();
    }
}
