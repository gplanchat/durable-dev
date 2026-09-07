<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Profiler\TemporalEventConverter;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\ContinueAsNewOptions;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\TaskQueue;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionFailedEventAttributes;

/**
 * The Temporal driver flattened every workflow failure onto a raw message: the `kind` of
 * WorkflowExecutionFailed was lost and the domain event could not be rebuilt when reading
 * the history back. And a ContinueAsNew became a workflow failure.
 */
final class TemporalWorkflowFailureRoundTripTest extends TestCase
{
    private function buffer(): TemporalWorkflowCommandBuffer
    {
        return new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'exec-1');
    }

    public function testUnhandledActivityFailureKindSurvivesTheRoundTrip(): void
    {
        $cause = new DurableActivityFailedException(
            'act-9',
            'charge_card',
            2,
            new FailureEnvelope('App\\PaymentException', 'card declined', 42, [], null, []),
        );

        $buffer = $this->buffer();
        $buffer->failWorkflow($cause);

        $command = $buffer->peek()[0];
        self::assertSame(CommandType::COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION, $command->getCommandType());
        $failure = $command->getFailWorkflowExecutionCommandAttributes()?->getFailure();
        self::assertNotNull($failure);
        self::assertSame(DurableActivityFailedException::class, $failure->getApplicationFailureInfo()?->getType());

        // Reading the history back: the original kind must be given back.
        $attrs = new WorkflowExecutionFailedEventAttributes();
        $attrs->setFailure($failure);
        $event = new HistoryEvent();
        $event->setEventId(7);
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED);
        $event->setWorkflowExecutionFailedEventAttributes($attrs);

        $decoded = (new TemporalEventConverter('exec-1'))->convert($event);
        self::assertInstanceOf(WorkflowExecutionFailed::class, $decoded);
        self::assertSame(WorkflowExecutionFailed::KIND_UNHANDLED_ACTIVITY, $decoded->kind());
        self::assertSame('act-9', $decoded->context()['activityId'] ?? null);
        self::assertSame('charge_card', $decoded->context()['activityName'] ?? null);
    }

    public function testContinueAsNewEmitsItsOwnCommandNotAFailure(): void
    {
        $buffer = $this->buffer();
        $buffer->continueAsNew('NextWorkflow', ['cursor' => 12], new ContinueAsNewOptions(taskQueue: TaskQueue::named('next-queue')));

        $command = $buffer->peek()[0];
        self::assertSame(CommandType::COMMAND_TYPE_CONTINUE_AS_NEW_WORKFLOW_EXECUTION, $command->getCommandType());
        $attrs = $command->getContinueAsNewWorkflowExecutionCommandAttributes();
        self::assertNotNull($attrs);
        self::assertSame('NextWorkflow', $attrs->getWorkflowType()?->getName());
        self::assertSame('next-queue', $attrs->getTaskQueue()?->getName());
    }
}
