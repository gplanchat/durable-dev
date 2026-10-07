<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Exception\ActivityFailureCauseException;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionFailedEventAttributes;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;

/**
 * #872: an unhandled activity failure came back from pollForCompletion() as a bare
 * \RuntimeException, where the journal backends throw DurableWorkflowAlgorithmFailureException
 * ({@see \Gplanchat\Durable\Store\EventStoreWorkflowLifecycle::onFailed()}).
 */
final class PollForCompletionFailureTest extends TestCase
{
    /** @return iterable<string, array{\Throwable, string}> */
    public static function activityFailures(): iterable
    {
        yield 'activity failure' => [
            new DurableActivityFailedException('act-9', 'charge_card', 2, new FailureEnvelope('App\\PaymentException', 'card declined', 42, [], null, [])),
            'Workflow did not handle activity failure: ',
        ];
        yield 'catastrophic activity failure' => [
            new DurableCatastrophicActivityFailureException(ActivityCatastrophicFailure::forThrowable(ExecutionId::fromString('exec-1'), 'act-9', 'charge_card', 1, new \LogicException('no handler'), 'handler_missing')),
            'Workflow did not handle catastrophic activity failure: ',
        ];
        yield 'superseded activity' => [
            new ActivitySupersededException('act-9', 'race_superseded'),
            'Workflow did not handle superseded activity: ',
        ];
        yield 'declared activity failure' => [
            new DeclinedCardFailure('card declined'),
            'Workflow did not handle declared activity failure: ',
        ];
    }

    #[DataProvider('activityFailures')]
    public function testAnUnhandledActivityFailureIsAnAlgorithmFailureAsOnTheJournal(\Throwable $cause, string $prefix): void
    {
        try {
            $this->clientClosedBy($cause)->pollForCompletion('exec-1', 1, 1);
            self::fail('a failed workflow must throw');
        } catch (DurableWorkflowAlgorithmFailureException $thrown) {
        }

        self::assertSame($prefix . $cause->getMessage(), $thrown->getMessage());
        $previous = $thrown->getPrevious();
        self::assertInstanceOf(ActivityFailureCauseException::class, $previous, 'the original failure travels as previous');
        self::assertSame($cause::class, $previous->originalExceptionClass());
        self::assertStringEndsWith($cause->getMessage(), $previous->getMessage());
    }

    /** The history a worker writes when the workflow lets this cause escape. */
    private function clientClosedBy(\Throwable $cause): WorkflowClient
    {
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), ExecutionId::fromString('exec-1'));
        $buffer->failWorkflow($cause);
        $attrs = new WorkflowExecutionFailedEventAttributes();
        $attrs->setFailure($buffer->peek()[0]->getFailWorkflowExecutionCommandAttributes()?->getFailure());
        $event = new HistoryEvent();
        $event->setEventId(7);
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED);
        $event->setWorkflowExecutionFailedEventAttributes($attrs);

        $grpc = $this->createStub(WorkflowServiceClientInterface::class);
        $grpc->method('GetWorkflowExecutionHistory')->willReturn(new GetWorkflowExecutionHistoryResponse(['history' => new History(['events' => [$event]])]));
        $connection = TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default&tls=0');

        return new WorkflowClient($grpc, $connection, new TemporalHistoryCursor($grpc, $connection), new WorkflowServiceExecutionRpc($grpc));
    }
}

final class DeclinedCardFailure extends \RuntimeException implements DeclaredActivityFailureInterface
{
    public function toActivityFailureContext(): array
    {
        return [];
    }

    public static function restoreFromActivityFailureContext(array $context): static
    {
        return new static('card declined');
    }
}
