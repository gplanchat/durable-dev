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
use Temporal\Api\Workflowservice\V1\SignalWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\SignalWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\UpdateWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\UpdateWorkflowExecutionResponse;

/**
 * The cluster drops a duplicate signal by its `request_id` and a duplicate update by its
 * `update_id`: the id the caller gives is the one on the wire (#333).
 *
 * @internal
 */
#[CoversClass(WorkflowClient::class)]
final class WorkflowClientRequestIdTest extends TestCase
{
    /** @var list<object> */
    private array $sent = [];

    public function testASignalCarriesTheRequestIdOfTheCaller(): void
    {
        $this->client()->signal('wf-1', 'approve', [], 'sig-42');

        self::assertInstanceOf(SignalWorkflowExecutionRequest::class, $this->sent[0]);
        self::assertSame('sig-42', $this->sent[0]->getRequestId());
    }

    public function testASignalWithoutARequestIdStillCarriesOne(): void
    {
        $this->client()->signal('wf-1', 'approve');

        self::assertInstanceOf(SignalWorkflowExecutionRequest::class, $this->sent[0]);
        self::assertNotSame('', $this->sent[0]->getRequestId());
    }

    public function testAnUpdateCarriesTheUpdateIdOfTheCaller(): void
    {
        $this->client()->update('wf-1', 'setDiscount', [], 'upd-42');

        self::assertInstanceOf(UpdateWorkflowExecutionRequest::class, $this->sent[0]);
        self::assertSame('upd-42', $this->sent[0]->getRequest()?->getMeta()?->getUpdateId());
    }

    private function client(): WorkflowClient
    {
        $grpc = $this->createMock(WorkflowServiceClientInterface::class);
        $grpc->method('SignalWorkflowExecution')->willReturnCallback(function (SignalWorkflowExecutionRequest $request) {
            $this->sent[] = $request;

            return new SignalWorkflowExecutionResponse();
        });
        $grpc->method('UpdateWorkflowExecution')->willReturnCallback(function (UpdateWorkflowExecutionRequest $request) {
            $this->sent[] = $request;

            return new UpdateWorkflowExecutionResponse();
        });
        $connection = TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default&tls=0');

        return new WorkflowClient($grpc, $connection, new TemporalHistoryCursor($grpc, $connection), new WorkflowServiceExecutionRpc($grpc));
    }
}
