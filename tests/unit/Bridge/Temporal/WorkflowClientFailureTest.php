<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Exception\WorkflowFailedException;
use Gplanchat\Durable\Exception\WorkflowTerminatedException;
use Gplanchat\Durable\Exception\WorkflowTimedOutException;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Failure\V1\ApplicationFailureInfo;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionCanceledEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionFailedEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionTerminatedEventAttributes;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;

/**
 * pollForCompletion() reports a closed workflow with the exception the journal backends raise
 * for the same outcome (#872). Each failure is written by the worker's command buffer, then read
 * back from the close event, as on a cluster.
 *
 * @internal
 */
#[CoversClass(WorkflowClient::class)]
final class WorkflowClientFailureTest extends TestCase
{
    public function testAWorkflowExceptionComesBackAsItsOwnClass(): void
    {
        $caught = $this->pollFailedWith(new OrderRejected('order 42 rejected', 7));

        self::assertInstanceOf(OrderRejected::class, $caught);
        self::assertSame('order 42 rejected', $caught->getMessage());
        self::assertSame(7, $caught->getCode());
    }

    public function testAnExceptionOutsideRuntimeExceptionComesBackAsItsOwnClass(): void
    {
        $caught = $this->pollFailedWith(new \LogicException('impossible branch'));

        self::assertSame(\LogicException::class, $caught::class);
        self::assertSame('impossible branch', $caught->getMessage());
    }

    public function testAnExceptionItsConstructorCannotRebuildComesBackAsWorkflowFailed(): void
    {
        $caught = $this->pollFailedWith(new OrderNotFound('order-42'));

        self::assertInstanceOf(WorkflowFailedException::class, $caught);
        self::assertSame('Workflow "exec-1" failed: Order order-42 not found', $caught->getMessage());
    }

    public function testAnExceptionClassTheClientCannotLoadComesBackAsWorkflowFailed(): void
    {
        $failure = $this->failureOf(new \RuntimeException('gone'));
        $failure->getApplicationFailureInfo()?->setDetails(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode([
            'kind' => 'workflow_handler_failure',
            'failureClass' => 'App\\Missing\\GoneException',
            'failureMessage' => 'gone',
            'failureCode' => 0,
            'context' => [],
        ])));

        $caught = $this->poll($this->failedEvent($failure));

        self::assertInstanceOf(WorkflowFailedException::class, $caught);
        self::assertSame('Workflow "exec-1" failed: gone', $caught->getMessage());
    }

    public function testAFailureWithoutDurableDetailsComesBackAsWorkflowFailed(): void
    {
        $failure = new Failure();
        $failure->setMessage('written by another worker');
        $failure->setApplicationFailureInfo(new ApplicationFailureInfo(['type' => 'SomeGoError']));

        $caught = $this->poll($this->failedEvent($failure));

        self::assertInstanceOf(WorkflowFailedException::class, $caught);
        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertSame('Workflow "exec-1" failed: written by another worker', $caught->getMessage());
    }

    public function testACancelledWorkflowComesBackAsWorkflowCancelledWithItsReason(): void
    {
        $attrs = new WorkflowExecutionCanceledEventAttributes();
        $attrs->setDetails(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode(['reason' => 'customer left'])));
        $event = new HistoryEvent(['event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCELED]);
        $event->setWorkflowExecutionCanceledEventAttributes($attrs);

        $caught = $this->poll($event);

        self::assertInstanceOf(WorkflowCancelledException::class, $caught);
        self::assertSame('exec-1', $caught->executionId);
        self::assertSame('customer left', $caught->reason);
    }

    public function testATimedOutWorkflowComesBackAsWorkflowTimedOut(): void
    {
        $caught = $this->poll(new HistoryEvent(['event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_TIMED_OUT]));

        self::assertInstanceOf(WorkflowTimedOutException::class, $caught);
        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertSame('exec-1', $caught->executionId);
    }

    public function testATerminatedWorkflowComesBackAsWorkflowTerminatedWithItsReason(): void
    {
        $event = new HistoryEvent(['event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_TERMINATED]);
        $event->setWorkflowExecutionTerminatedEventAttributes(new WorkflowExecutionTerminatedEventAttributes(['reason' => 'stuck order']));

        $caught = $this->poll($event);

        self::assertInstanceOf(WorkflowTerminatedException::class, $caught);
        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertSame('exec-1', $caught->executionId);
        self::assertSame('stuck order', $caught->reason);
    }

    private function pollFailedWith(\Throwable $reason): \Throwable
    {
        return $this->poll($this->failedEvent($this->failureOf($reason)));
    }

    private function failureOf(\Throwable $reason): Failure
    {
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), ExecutionId::fromString('exec-1'));
        $buffer->failWorkflow($reason);
        $failure = $buffer->peek()[0]->getFailWorkflowExecutionCommandAttributes()?->getFailure();
        self::assertNotNull($failure);

        return $failure;
    }

    private function failedEvent(Failure $failure): HistoryEvent
    {
        $event = new HistoryEvent(['event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED]);
        $event->setWorkflowExecutionFailedEventAttributes(new WorkflowExecutionFailedEventAttributes(['failure' => $failure]));

        return $event;
    }

    private function poll(HistoryEvent $closeEvent): \Throwable
    {
        $grpc = $this->createMock(WorkflowServiceClientInterface::class);
        $grpc->method('GetWorkflowExecutionHistory')->willReturn(
            new GetWorkflowExecutionHistoryResponse(['history' => new History(['events' => [$closeEvent]])]),
        );
        $connection = TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default&tls=0');
        $client = new WorkflowClient($grpc, $connection, new TemporalHistoryCursor($grpc, $connection), new WorkflowServiceExecutionRpc($grpc));

        try {
            $client->pollForCompletion('exec-1', 0, 1);
        } catch (\Throwable $caught) {
            return $caught;
        }

        self::fail('pollForCompletion() returned instead of throwing');
    }
}

final class OrderRejected extends \RuntimeException {}

final class OrderNotFound extends \RuntimeException
{
    public function __construct(string $orderId)
    {
        parent::__construct(\sprintf('Order %s not found', $orderId));
    }
}
