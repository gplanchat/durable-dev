<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Store;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Store\TemporalEventConverter;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Failure\V1\ApplicationFailureInfo;
use Temporal\Api\Failure\V1\ChildWorkflowExecutionFailureInfo;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\History\V1\ChildWorkflowExecutionCanceledEventAttributes;
use Temporal\Api\History\V1\ChildWorkflowExecutionFailedEventAttributes;
use Temporal\Api\History\V1\ChildWorkflowExecutionTerminatedEventAttributes;
use Temporal\Api\History\V1\ChildWorkflowExecutionTimedOutEventAttributes;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\StartChildWorkflowExecutionFailedEventAttributes;

/**
 * A child that ends without a result becomes a ChildWorkflowFailed on the parent's journal.
 */
final class ChildWorkflowFailedConversionTest extends TestCase
{
    public function testAChildThatFailedCarriesItsFailureKindAndClass(): void
    {
        $stored = ['kind' => 'workflow_handler_failure', 'failureClass' => 'App\\Boom', 'failureMessage' => 'boom', 'failureCode' => 7, 'context' => ['a' => 1]];
        $failure = (new Failure())->setMessage('boom')->setApplicationFailureInfo(
            (new ApplicationFailureInfo())->setDetails((new Payloads())->setPayloads([JsonPlainPayload::encode($stored)])),
        );
        $event = $this->event(EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_FAILED);
        $event->setChildWorkflowExecutionFailedEventAttributes(
            (new ChildWorkflowExecutionFailedEventAttributes())->setWorkflowExecution($this->execution())->setFailure($failure),
        );

        $read = $this->convert($event);

        self::assertSame('child-1', $read->childExecutionId()->toString());
        self::assertSame('Child workflow child-1 failed.', $read->failureMessage());
        self::assertSame('workflow_handler_failure', $read->workflowFailureKind());
        self::assertSame('App\\Boom', $read->workflowFailureClass());
        self::assertSame(['a' => 1], $read->workflowFailureContext());
    }

    public function testAChildThatFailedReadsTheKindFromTheNestedCauseOfTheWrapper(): void
    {
        $stored = ['kind' => 'workflow_handler_failure', 'failureClass' => 'App\\Boom', 'failureMessage' => 'boom', 'failureCode' => 7, 'context' => ['a' => 1]];
        $cause = (new Failure())->setMessage('boom')->setApplicationFailureInfo(
            (new ApplicationFailureInfo())->setDetails((new Payloads())->setPayloads([JsonPlainPayload::encode($stored)])),
        );
        $wrapper = (new Failure())->setMessage('Child Workflow execution failed')
            ->setChildWorkflowExecutionFailureInfo(new ChildWorkflowExecutionFailureInfo())
            ->setCause($cause);
        $event = $this->event(EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_FAILED);
        $event->setChildWorkflowExecutionFailedEventAttributes(
            (new ChildWorkflowExecutionFailedEventAttributes())->setWorkflowExecution($this->execution())->setFailure($wrapper),
        );

        $read = $this->convert($event);

        self::assertSame('workflow_handler_failure', $read->workflowFailureKind());
        self::assertSame('App\\Boom', $read->workflowFailureClass());
        self::assertSame(7, $read->failureCode());
        self::assertSame(['a' => 1], $read->workflowFailureContext());
    }

    public function testAChildThatFailedWithoutDetailsLeavesKindAndClassEmpty(): void
    {
        $event = $this->event(EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_FAILED);
        $event->setChildWorkflowExecutionFailedEventAttributes(
            (new ChildWorkflowExecutionFailedEventAttributes())->setWorkflowExecution($this->execution()),
        );

        $read = $this->convert($event);

        self::assertSame('Child workflow child-1 failed.', $read->failureMessage());
        self::assertNull($read->workflowFailureKind());
        self::assertNull($read->workflowFailureClass());
    }

    public function testAChildThatCouldNotBeStarted(): void
    {
        $event = $this->event(EventType::EVENT_TYPE_START_CHILD_WORKFLOW_EXECUTION_FAILED);
        $event->setStartChildWorkflowExecutionFailedEventAttributes(
            (new StartChildWorkflowExecutionFailedEventAttributes())->setWorkflowId('child-1'),
        );

        $read = $this->convert($event);

        self::assertSame('child-1', $read->childExecutionId()->toString());
        self::assertSame('Child workflow child-1 could not be started.', $read->failureMessage());
        self::assertNull($read->workflowFailureKind());
        self::assertNull($read->workflowFailureClass());
    }

    public function testAChildThatTimedOut(): void
    {
        $event = $this->event(EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_TIMED_OUT);
        $event->setChildWorkflowExecutionTimedOutEventAttributes(
            (new ChildWorkflowExecutionTimedOutEventAttributes())->setWorkflowExecution($this->execution()),
        );

        $read = $this->convert($event);

        self::assertSame('Child workflow child-1 timed out.', $read->failureMessage());
        self::assertNull($read->workflowFailureKind());
        self::assertNull($read->workflowFailureClass());
    }

    public function testAChildThatWasCancelled(): void
    {
        $event = $this->event(EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_CANCELED);
        $event->setChildWorkflowExecutionCanceledEventAttributes(
            (new ChildWorkflowExecutionCanceledEventAttributes())->setWorkflowExecution($this->execution()),
        );

        $read = $this->convert($event);

        self::assertSame('Child workflow child-1 was cancelled.', $read->failureMessage());
        self::assertNull($read->workflowFailureKind());
        self::assertNull($read->workflowFailureClass());
    }

    public function testAChildThatWasTerminated(): void
    {
        $event = $this->event(EventType::EVENT_TYPE_CHILD_WORKFLOW_EXECUTION_TERMINATED);
        $event->setChildWorkflowExecutionTerminatedEventAttributes(
            (new ChildWorkflowExecutionTerminatedEventAttributes())->setWorkflowExecution($this->execution()),
        );

        $read = $this->convert($event);

        self::assertSame('Child workflow child-1 was terminated.', $read->failureMessage());
        self::assertNull($read->workflowFailureKind());
        self::assertNull($read->workflowFailureClass());
    }

    private function event(int $type): HistoryEvent
    {
        $event = new HistoryEvent();
        $event->setEventId(9);
        $event->setEventType($type);

        return $event;
    }

    private function execution(): WorkflowExecution
    {
        return new WorkflowExecution(['workflow_id' => 'child-1']);
    }

    private function convert(HistoryEvent $event): ChildWorkflowFailed
    {
        $read = (new TemporalEventConverter(ExecutionId::fromString('parent-1')))->convert($event);
        self::assertInstanceOf(ChildWorkflowFailed::class, $read);
        self::assertSame('parent-1', $read->executionId()->toString());

        return $read;
    }
}
