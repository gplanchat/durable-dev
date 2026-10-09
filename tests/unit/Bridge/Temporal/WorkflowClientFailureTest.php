<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Codec\WorkflowFailureCodec;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Exception\ActivityFailureCauseException;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\DurableNexusOperationFailedException;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Exception\WorkflowFailedException;
use Gplanchat\Durable\Exception\WorkflowTerminatedException;
use Gplanchat\Durable\Exception\WorkflowTimedOutException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Nexus\NexusOperationFailureKind;
use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;
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

    public function testAnUnhandledActivityFailureComesBackWrappedWithTheActivityFailureAsPrevious(): void
    {
        $cause = new DurableActivityFailedException('act-9', 'charge_card', 2, new FailureEnvelope(
            'App\\PaymentException',
            'card declined',
            42,
            [],
            null,
            [['class' => 'PDOException', 'message' => 'connection lost', 'code' => 0]],
        ));

        $caught = $this->pollFailedWith($cause);

        self::assertInstanceOf(DurableWorkflowAlgorithmFailureException::class, $caught);
        self::assertSame('Workflow did not handle activity failure: ' . $cause->getMessage(), $caught->getMessage());
        $previous = $caught->getPrevious();
        self::assertInstanceOf(DurableActivityFailedException::class, $previous);
        self::assertSame($cause->getMessage(), $previous->getMessage());
        self::assertSame(['act-9', 'charge_card', 2, 42], [$previous->activityId(), $previous->activityName(), $previous->attempt(), $previous->getCode()]);
        self::assertSame('App\\PaymentException', $previous->envelope()->class);
        // The chain comes back as the journal's replay gives it to the workflow.
        $chain = $previous->getPrevious();
        self::assertInstanceOf(ActivityFailureCauseException::class, $chain);
        self::assertSame('PDOException', $chain->originalExceptionClass());
        self::assertSame('[PDOException] connection lost', $chain->getMessage());
    }

    public function testAnUnhandledDeclaredActivityFailureComesBackWithTheDeclaredExceptionAsPrevious(): void
    {
        $caught = $this->pollFailedWith(new StockShortage('SKU-7', 3));

        self::assertInstanceOf(DurableWorkflowAlgorithmFailureException::class, $caught);
        self::assertSame('Workflow did not handle declared activity failure: SKU-7 is short by 3', $caught->getMessage());
        $previous = $caught->getPrevious();
        self::assertInstanceOf(StockShortage::class, $previous);
        self::assertSame(['SKU-7', 3], [$previous->sku, $previous->missing]);
    }

    public function testASupersededActivityComesBackWithTheSupersededActivityAsPrevious(): void
    {
        $caught = $this->pollFailedWith(new ActivitySupersededException('act-5', 'race_superseded'));

        self::assertInstanceOf(DurableWorkflowAlgorithmFailureException::class, $caught);
        self::assertSame('Workflow did not handle superseded activity: Activity act-5 was superseded (race_superseded)', $caught->getMessage());
        $previous = $caught->getPrevious();
        self::assertInstanceOf(ActivitySupersededException::class, $previous);
        self::assertSame(['act-5', 'race_superseded'], [$previous->activityId(), $previous->cancellationReason()]);
    }

    public function testACatastrophicActivityFailureComesBackWithTheCatastrophicFailureAsPrevious(): void
    {
        $cause = new DurableCatastrophicActivityFailureException(ActivityCatastrophicFailure::forThrowable(
            ExecutionId::fromString('exec-1'),
            'act-3',
            'reserve_stock',
            2,
            new \RuntimeException('NAN in payload'),
            'json_encode_failed',
        ));

        $caught = $this->pollFailedWith($cause);

        self::assertInstanceOf(DurableWorkflowAlgorithmFailureException::class, $caught);
        self::assertSame('Workflow did not handle catastrophic activity failure: ' . $cause->getMessage(), $caught->getMessage());
        $previous = $caught->getPrevious();
        self::assertInstanceOf(DurableCatastrophicActivityFailureException::class, $previous);
        self::assertSame($cause->getMessage(), $previous->getMessage());
        self::assertSame(['act-3', 'reserve_stock', 2], [$previous->activityId(), $previous->activityName(), $previous->attempt()]);
    }

    public function testAnActivityFailureFromAnOlderHistoryKeepsTheServerMessageAsPrevious(): void
    {
        $failure = $this->failureOf(new \RuntimeException('unused'));
        $failure->setMessage('[charge_card / act-9] attempt=2 failed');
        $failure->getApplicationFailureInfo()?->setDetails(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode([
            'kind' => 'unhandled_activity_failure',
            'failureClass' => DurableActivityFailedException::class,
            'failureMessage' => '[charge_card / act-9] attempt=2 failed',
            'failureCode' => 0,
            'context' => ['activityId' => 'act-9', 'activityName' => 'charge_card'],
        ])));

        $caught = $this->poll($this->failedEvent($failure));

        self::assertInstanceOf(DurableWorkflowAlgorithmFailureException::class, $caught);
        self::assertInstanceOf(WorkflowFailedException::class, $caught->getPrevious());
        self::assertSame('Workflow "exec-1" failed: [charge_card / act-9] attempt=2 failed', $caught->getPrevious()->getMessage());
    }

    public function testAnUnhandledNexusFailureComesBackAsItsOwnClass(): void
    {
        $cause = new DurableNexusOperationFailedException('payments', 'Billing', 'charge', NexusOperationFailureKind::HandlerError, new FailureEnvelope('App\\Declined', 'card declined', 3), 'non_retryable');

        $caught = $this->pollFailedWith($cause);

        self::assertInstanceOf(DurableNexusOperationFailedException::class, $caught);
        self::assertSame($cause->getMessage(), $caught->getMessage());
        self::assertSame(3, $caught->getCode());
        self::assertSame(['payments', 'Billing', 'charge', NexusOperationFailureKind::HandlerError, 'non_retryable'], [$caught->endpoint(), $caught->service(), $caught->operation(), $caught->kind(), $caught->retryBehaviour()]);
        self::assertSame('App\\Declined', $caught->envelope()->class);
    }

    public function testAnElapsedDeadlineComesBackAsDeadlineExceeded(): void
    {
        $cause = new DeadlineExceededException(Duration::seconds(30.0), 'activity charge_card');

        $caught = $this->pollFailedWith($cause);

        self::assertInstanceOf(DeadlineExceededException::class, $caught);
        self::assertSame($cause->getMessage(), $caught->getMessage());
        self::assertSame('activity charge_card', $caught->awaited());
    }

    public function testAnElapsedDeadlineCarriesThePreviousExceptionTheWorkflowSaw(): void
    {
        $cause = new DeadlineExceededException(Duration::seconds(30.0), 'activity charge_card', new OrderRejected('order 42 rejected', 7));

        $caught = $this->pollFailedWith($cause);

        self::assertInstanceOf(DeadlineExceededException::class, $caught);
        self::assertInstanceOf(OrderRejected::class, $caught->getPrevious());
        self::assertSame('order 42 rejected', $caught->getPrevious()->getMessage());
        self::assertSame(7, $caught->getPrevious()->getCode());
    }

    public function testAnElapsedDeadlineWhosePreviousClassCannotBeLoadedStillComesBackWithoutPrevious(): void
    {
        $failure = $this->failureOf(new DeadlineExceededException(Duration::seconds(30.0), 'x'));
        $details = WorkflowFailureCodec::details($failure);
        $details['cause'] = ['previous' => ['class' => 'App\\Gone', 'message' => 'gone', 'code' => 0]];
        $failure->getApplicationFailureInfo()->setDetails(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($details)));

        $caught = $this->poll($this->failedEvent($failure));

        self::assertInstanceOf(DeadlineExceededException::class, $caught);
        self::assertNull($caught->getPrevious());
    }

    public function testAFailureWithDetailsThatAreNotJsonComesBackAsWorkflowFailed(): void
    {
        $failure = new Failure();
        $failure->setMessage('encrypted by another SDK');
        $failure->setApplicationFailureInfo(new ApplicationFailureInfo([
            'type' => 'SomeGoError',
            'details' => new Payloads(['payloads' => [new Payload([
                'metadata' => ['encoding' => 'binary/encrypted'],
                'data' => "\x0a\x03\xff\xfe",
            ])]]),
        ]));

        $caught = $this->poll($this->failedEvent($failure));

        self::assertInstanceOf(WorkflowFailedException::class, $caught);
        self::assertSame('Workflow "exec-1" failed: encrypted by another SDK', $caught->getMessage());
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

final class StockShortage extends \RuntimeException implements DeclaredActivityFailureInterface
{
    public function __construct(public readonly string $sku, public readonly int $missing)
    {
        parent::__construct(\sprintf('%s is short by %d', $sku, $missing));
    }

    public function toActivityFailureContext(): array
    {
        return ['sku' => $this->sku, 'missing' => $this->missing];
    }

    public static function restoreFromActivityFailureContext(array $context): static
    {
        return new static((string) $context['sku'], (int) $context['missing']);
    }
}

final class OrderNotFound extends \RuntimeException
{
    public function __construct(string $orderId)
    {
        parent::__construct(\sprintf('Order %s not found', $orderId));
    }
}
