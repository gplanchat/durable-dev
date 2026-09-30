<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Codec\PayloadCodecInterface;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;

/**
 * DUR055's acceptance: what the server stores holds no value in clear, and the application still
 * reads its own. No worker: a started execution's input is enough, and it is in the history.
 */
final class PayloadCodecTest extends TestCase
{
    private TemporalConnection $connection;
    private ?WorkflowServiceClientInterface $plain = null;
    private string $workflowId;

    protected function setUp(): void
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS not set: no Temporal server.');
        }
        $this->connection = new TemporalConnection(
            target: $address,
            namespace: getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test',
            transport: TemporalConnection::TRANSPORT_GRPC_CURL,
        );
        $this->plain = WorkflowServiceClientFactory::create($this->connection);
        $this->workflowId = 'payload-codec-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (null === $this->plain) {
            return;
        }
        $request = new TerminateWorkflowExecutionRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $this->workflowId]));

        try {
            $this->plain->TerminateWorkflowExecution($request, [], ['timeout' => 5_000_000]);
        } catch (\RuntimeException) {
            // Never started: not the subject of the test.
        }
    }

    public function testTheServerStoresTheInputEncodedAndTheApplicationReadsItBack(): void
    {
        $encoded = WorkflowServiceClientFactory::create($this->connection, codec: new XorCodec());

        $start = new StartWorkflowExecutionRequest();
        $start->setNamespace($this->connection->namespace->name());
        $start->setWorkflowId($this->workflowId);
        $start->setWorkflowType(new WorkflowType(['name' => 'PayloadCodecProbe']));
        $start->setTaskQueue(new TaskQueue(['name' => 'payload-codec-unserved']));
        $start->setRequestId(bin2hex(random_bytes(8)));
        $start->setInput(new Payloads(['payloads' => [new Payload(['metadata' => ['encoding' => 'json/plain'], 'data' => '"order-42"'])]]));
        $encoded->StartWorkflowExecution($start, [], ['timeout' => 10_000_000]);

        self::assertNotNull($this->plain);
        self::assertStringNotContainsString('order-42', $this->startedInput($this->plain), 'the server must not hold it in clear');
        self::assertSame('"order-42"', $this->startedInput($encoded));
    }

    private function startedInput(WorkflowServiceClientInterface $client): string
    {
        $request = new GetWorkflowExecutionHistoryRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setExecution(new WorkflowExecution(['workflow_id' => $this->workflowId]));
        $event = $client->GetWorkflowExecutionHistory($request, [], ['timeout' => 10_000_000])->getHistory()?->getEvents()[0];

        return (string) $event?->getWorkflowExecutionStartedEventAttributes()?->getInput()?->getPayloads()[0]->getData();
    }
}

/**
 * XORs the bytes and marks the payload: reversible and unreadable at a glance, not a cipher.
 */
final class XorCodec implements PayloadCodecInterface
{
    public function encode(Payload $payload): Payload
    {
        return new Payload(['metadata' => ['encoding' => 'test/xor'], 'data' => $payload->getData() ^ str_repeat("\x5A", \strlen($payload->getData()))]);
    }

    public function decode(Payload $payload): Payload
    {
        if ('test/xor' !== (iterator_to_array($payload->getMetadata())['encoding'] ?? null)) {
            return $payload;
        }

        return new Payload(['metadata' => ['encoding' => 'json/plain'], 'data' => $payload->getData() ^ str_repeat("\x5A", \strlen($payload->getData()))]);
    }
}
