<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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

    public function testCancelRequestsCancellationOfTheWorkflowWithTheRequestIdOfTheCaller(): void
    {
        $this->client()->cancel('wf-1', 'cancel-42');

        $request = $this->sent[0];
        self::assertInstanceOf(RequestCancelWorkflowExecutionRequest::class, $request);
        self::assertSame('wf-1', $request->getWorkflowExecution()?->getWorkflowId());
        self::assertSame('cancel-42', $request->getRequestId());
        self::assertSame('default', $request->getNamespace());
    }

    public function testCancelWithoutARequestIdStillCarriesOne(): void
    {
        $this->client()->cancel('wf-1');

        self::assertInstanceOf(RequestCancelWorkflowExecutionRequest::class, $this->sent[0]);
        self::assertNotSame('', $this->sent[0]->getRequestId());
    }

    public function testTerminateEndsTheWorkflowWithTheReasonOfTheCaller(): void
    {
        $this->client()->terminate('wf-1', 'no longer needed');

        $request = $this->sent[0];
        self::assertInstanceOf(TerminateWorkflowExecutionRequest::class, $request);
        self::assertSame('wf-1', $request->getWorkflowExecution()?->getWorkflowId());
        self::assertSame('no longer needed', $request->getReason());
        self::assertSame('default', $request->getNamespace());
    }

    public function testCancellingAnEndedExecutionFailsWithOneDefinedOutcome(): void
    {
        $this->serverFailure = new \RuntimeException('workflow execution already completed', 5);

        try {
            $this->client()->cancel('wf-1');
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Workflow "wf-1" has ended or does not exist.', $failure->getMessage());
            self::assertSame(5, $failure->getCode());
            self::assertSame($this->serverFailure, $failure->getPrevious());
        }
    }

    public function testTerminatingAnEndedExecutionFailsWithTheSameOutcome(): void
    {
        $this->serverFailure = new \RuntimeException('workflow execution already completed', 5);

        try {
            $this->client()->terminate('wf-1');
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Workflow "wf-1" has ended or does not exist.', $failure->getMessage());
            self::assertSame(5, $failure->getCode());
            self::assertSame($this->serverFailure, $failure->getPrevious());
        }
    }

    public function testOtherServerFailuresPropagateUnchanged(): void
    {
        $this->serverFailure = new \RuntimeException('unavailable', 14);

        $this->expectExceptionMessage('unavailable');
        $this->client()->cancel('wf-1');
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
        $connection = TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default&tls=0');

        return new WorkflowClient($grpc, $connection, new TemporalHistoryCursor($grpc, $connection), new WorkflowServiceExecutionRpc($grpc));
    }
}
