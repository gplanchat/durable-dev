<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Port;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Port\TemporalWorkflowClientPort;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Memo;
use Temporal\Api\Workflow\V1\WorkflowExecutionInfo;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\SignalWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionResponse;

/**
 * How the Temporal port reads the server's answers: the gRPC errors it maps to the port's
 * exceptions, on responses built by hand. The run against a real server is the integration suite's.
 *
 * @internal
 */
#[CoversClass(TemporalWorkflowClientPort::class)]
final class TemporalWorkflowClientPortTest extends TestCase
{
    /** @var WorkflowServiceClientInterface&Stub */
    private WorkflowServiceClientInterface $grpc;
    private bool $described = true;

    protected function setUp(): void
    {
        $this->grpc = $this->createStub(WorkflowServiceClientInterface::class);
        $this->grpc->method('DescribeWorkflowExecution')->willReturnCallback(fn() => $this->described
            ? new DescribeWorkflowExecutionResponse(['workflow_execution_info' => new WorkflowExecutionInfo(['memo' => new Memo()])])
            : throw new \RuntimeException('workflow not found', 5));
    }

    public function testAStartReturnsTheId(): void
    {
        $this->grpc->method('StartWorkflowExecution')->willReturn(new StartWorkflowExecutionResponse());

        self::assertSame('exec-1', $this->port()->start(ExecutionId::fromString('exec-1'), 'order')->toString());
    }

    public function testASecondStartFailsAsAlreadyStarted(): void
    {
        $answer = new \RuntimeException('workflow execution already started', 6);
        $this->grpc->method('StartWorkflowExecution')->willThrowException($answer);

        try {
            $this->port()->start(ExecutionId::fromString('exec-1'), 'order');
            self::fail('A second start must fail.');
        } catch (WorkflowExecutionAlreadyStarted $e) {
            self::assertSame('exec-1', $e->executionId);
            self::assertSame($answer, $e->getPrevious());
        }
    }

    public function testSignalingAnEndedExecutionFailsAsAlreadyEnded(): void
    {
        $this->grpc->method('SignalWorkflowExecution')->willThrowException(new \RuntimeException('workflow execution already completed', 5));

        $this->expectException(WorkflowExecutionAlreadyEnded::class);
        $this->port()->signal(ExecutionId::fromString('exec-1'), 'pay');
    }

    public function testSignalingAnUnknownExecutionFailsAsNotFound(): void
    {
        $this->described = false;
        $this->grpc->method('SignalWorkflowExecution')->willThrowException(new \RuntimeException('workflow not found', 5));

        $this->expectException(WorkflowExecutionNotFound::class);
        $this->port()->signal(ExecutionId::fromString('exec-1'), 'pay');
    }

    public function testASignalReachesTheWorkflowIdOfTheExecution(): void
    {
        $this->grpc->method('SignalWorkflowExecution')->willReturnCallback(function ($request) {
            self::assertSame('durable-exec-1', $request->getWorkflowExecution()?->getWorkflowId());
            self::assertSame('req-1', $request->getRequestId());

            return new SignalWorkflowExecutionResponse();
        });

        $this->port()->signal(ExecutionId::fromString('exec-1'), 'pay', [], 'req-1');
    }

    private function port(): TemporalWorkflowClientPort
    {
        $connection = TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default&tls=0');

        return new TemporalWorkflowClientPort(new WorkflowClient($this->grpc, $connection, new TemporalHistoryCursor($this->grpc, $connection), new WorkflowServiceExecutionRpc($this->grpc)));
    }
}
