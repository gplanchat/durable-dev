<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Memo;
use Temporal\Api\Enums\V1\WorkflowExecutionStatus;
use Temporal\Api\Workflow\V1\WorkflowExecutionInfo;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\RequestCancelWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\RequestCancelWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionResponse;

/**
 * Cancelling and terminating an execution from the application (#971). The server answers
 * NotFound for an execution that has already ended; the client maps it to one outcome.
 *
 * @internal
 */
#[CoversClass(WorkflowClient::class)]
final class WorkflowClientCancelTerminateTest extends TestCase
{
    /** @var list<object> */
    private array $sent = [];

    private ?\RuntimeException $serverFailure = null;

    /** Whether the server still holds the execution once it has ended, as it does until retention. */
    private bool $described = false;

    private int $status = WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING;

    public function testCancelRequestsCancellationOfTheWorkflowWithTheRequestIdOfTheCaller(): void
    {
        $this->client()->cancel(ExecutionId::fromString('exec-1'), 'cancel-42');

        $request = $this->sent[0];
        self::assertInstanceOf(RequestCancelWorkflowExecutionRequest::class, $request);
        self::assertSame('durable-exec-1', $request->getWorkflowExecution()?->getWorkflowId());
        self::assertSame('cancel-42', $request->getRequestId());
        self::assertSame('default', $request->getNamespace());
    }

    public function testCancelWithoutARequestIdStillCarriesOne(): void
    {
        $this->client()->cancel(ExecutionId::fromString('exec-1'));

        self::assertInstanceOf(RequestCancelWorkflowExecutionRequest::class, $this->sent[0]);
        self::assertNotSame('', $this->sent[0]->getRequestId());
    }

    public function testTerminateEndsTheWorkflowWithTheReasonOfTheCaller(): void
    {
        $this->client()->terminate(ExecutionId::fromString('exec-1'), 'no longer needed');

        $request = $this->sent[0];
        self::assertInstanceOf(TerminateWorkflowExecutionRequest::class, $request);
        self::assertSame('durable-exec-1', $request->getWorkflowExecution()?->getWorkflowId());
        self::assertSame('no longer needed', $request->getReason());
        self::assertSame('default', $request->getNamespace());
    }

    public function testCancellingAnEndedExecutionFailsAsAlreadyEnded(): void
    {
        $this->serverFailure = new \RuntimeException('workflow execution already completed', 5);
        $this->described = true;

        try {
            $this->client()->cancel(ExecutionId::fromString('exec-1'));
            self::fail('Expected WorkflowExecutionAlreadyEnded.');
        } catch (WorkflowExecutionAlreadyEnded $failure) {
            self::assertSame('exec-1', $failure->executionId);
            self::assertSame($this->serverFailure, $failure->getPrevious());
        }
    }

    public function testTerminatingAnEndedExecutionFailsAsAlreadyEnded(): void
    {
        $this->serverFailure = new \RuntimeException('workflow execution already completed', 5);
        $this->described = true;

        try {
            $this->client()->terminate(ExecutionId::fromString('exec-1'));
            self::fail('Expected WorkflowExecutionAlreadyEnded.');
        } catch (WorkflowExecutionAlreadyEnded $failure) {
            self::assertSame('exec-1', $failure->executionId);
            self::assertSame($this->serverFailure, $failure->getPrevious());
        }
    }

    public function testCancellingAnExecutionTheServerReportsAsClosedFailsAsAlreadyEndedWithoutSendingTheRequest(): void
    {
        $this->described = true;
        $this->status = WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_COMPLETED;

        try {
            $this->client()->cancel(ExecutionId::fromString('exec-1'));
            self::fail('Expected WorkflowExecutionAlreadyEnded.');
        } catch (WorkflowExecutionAlreadyEnded $failure) {
            self::assertSame('exec-1', $failure->executionId);
        }
        self::assertSame([], $this->sent, 'A server that accepts a cancel on a closed run must not be asked.');
    }

    public function testAnExecutionTheServerDoesNotHoldFailsAsNotFound(): void
    {
        $this->serverFailure = new \RuntimeException('workflow not found', 5);

        try {
            $this->client()->cancel(ExecutionId::fromString('exec-1'));
            self::fail('Expected WorkflowExecutionNotFound.');
        } catch (WorkflowExecutionNotFound $failure) {
            self::assertSame('exec-1', $failure->executionId);
            self::assertSame($this->serverFailure, $failure->getPrevious());
        }
    }

    public function testTerminatingAnExecutionTheServerDoesNotHoldFailsAsNotFound(): void
    {
        $this->serverFailure = new \RuntimeException('workflow not found', 5);

        try {
            $this->client()->terminate(ExecutionId::fromString('exec-1'));
            self::fail('Expected WorkflowExecutionNotFound.');
        } catch (WorkflowExecutionNotFound $failure) {
            self::assertSame('exec-1', $failure->executionId);
            self::assertSame($this->serverFailure, $failure->getPrevious());
        }
    }

    public function testOtherServerFailuresPropagateUnchanged(): void
    {
        $this->serverFailure = new \RuntimeException('unavailable', 14);

        $this->expectExceptionMessage('unavailable');
        $this->client()->cancel(ExecutionId::fromString('exec-1'));
    }

    private function client(): WorkflowClient
    {
        $grpc = $this->createMock(WorkflowServiceClientInterface::class);
        $grpc->method('RequestCancelWorkflowExecution')->willReturnCallback(function (RequestCancelWorkflowExecutionRequest $request) {
            $this->sent[] = $request;

            return $this->serverFailure ? throw $this->serverFailure : new RequestCancelWorkflowExecutionResponse();
        });
        $grpc->method('TerminateWorkflowExecution')->willReturnCallback(function (TerminateWorkflowExecutionRequest $request) {
            $this->sent[] = $request;

            return $this->serverFailure ? throw $this->serverFailure : new TerminateWorkflowExecutionResponse();
        });
        $grpc->method('DescribeWorkflowExecution')->willReturnCallback(fn() => $this->described
            ? new DescribeWorkflowExecutionResponse(['workflow_execution_info' => new WorkflowExecutionInfo(['memo' => new Memo(), 'status' => $this->status])])
            : throw new \RuntimeException('workflow not found', 5));
        $connection = TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default&tls=0');

        return new WorkflowClient($grpc, $connection, new TemporalHistoryCursor($grpc, $connection), new WorkflowServiceExecutionRpc($grpc));
    }
}
