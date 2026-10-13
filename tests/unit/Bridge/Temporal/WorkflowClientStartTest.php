<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionResponse;

/**
 * What a start does when the server reports the workflow id as already started: the engine's
 * `startAsync()` carries on, the client port's `start()` fails (task 3.3 of `workflow-repository`).
 *
 * @internal
 */
#[CoversClass(WorkflowClient::class)]
final class WorkflowClientStartTest extends TestCase
{
    private ?\RuntimeException $serverFailure = null;

    /** @return iterable<string, array{\RuntimeException}> */
    public static function alreadyStartedAnswers(): iterable
    {
        yield 'code 6' => [new \RuntimeException('workflow execution already started', 6)];
        yield 'message only' => [new \RuntimeException('Workflow execution is already running', 0)];
    }

    #[DataProvider('alreadyStartedAnswers')]
    public function testStartAsyncStillReturnsTheIdWhenTheWorkflowIsAlreadyStarted(\RuntimeException $answer): void
    {
        $this->serverFailure = $answer;

        $returned = $this->client()->startAsync('order', [], ExecutionId::fromString('exec-1'));

        self::assertSame('exec-1', $returned->toString());
    }

    #[DataProvider('alreadyStartedAnswers')]
    public function testStartFailsWhenTheWorkflowIsAlreadyStarted(\RuntimeException $answer): void
    {
        $this->serverFailure = $answer;

        try {
            $this->client()->start(ExecutionId::fromString('exec-1'), 'order');
            self::fail('A start of an already started workflow must fail.');
        } catch (WorkflowExecutionAlreadyStarted $e) {
            self::assertSame('exec-1', $e->executionId);
            self::assertSame($answer, $e->getPrevious());
        }
    }

    public function testStartReturnsTheIdAndSendsTheInput(): void
    {
        $sent = [];
        $returned = $this->client($sent)->start(ExecutionId::fromString('exec-1'), 'order', ['tag' => 'a']);

        self::assertSame('exec-1', $returned->toString());
        self::assertSame('durable-exec-1', $sent[0]->getWorkflowId());
        self::assertSame('order', $sent[0]->getWorkflowType()?->getName());
    }

    public function testAnotherServerFailureOfAStartPropagatesUnchanged(): void
    {
        $this->serverFailure = new \RuntimeException('unavailable', 14);

        $this->expectExceptionMessage('unavailable');
        $this->client()->start(ExecutionId::fromString('exec-1'), 'order');
    }

    /** @param list<StartWorkflowExecutionRequest> $sent */
    private function client(array &$sent = []): WorkflowClient
    {
        $grpc = $this->createStub(WorkflowServiceClientInterface::class);
        $grpc->method('StartWorkflowExecution')->willReturnCallback(function (StartWorkflowExecutionRequest $request) use (&$sent) {
            $sent[] = $request;

            return $this->serverFailure ? throw $this->serverFailure : new StartWorkflowExecutionResponse();
        });
        $connection = TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default&tls=0');

        return new WorkflowClient($grpc, $connection, new TemporalHistoryCursor($grpc, $connection), new WorkflowServiceExecutionRpc($grpc));
    }
}
