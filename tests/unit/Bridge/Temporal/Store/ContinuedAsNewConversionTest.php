<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Store;

use Google\Protobuf\Duration;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Store\TemporalEventConverter;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionContinuedAsNewEventAttributes;
use Temporal\Api\Taskqueue\V1\TaskQueue;

/**
 * The journal backends write a `WorkflowContinuedAsNew` when a run continues as new. Temporal
 * records WORKFLOW_EXECUTION_CONTINUED_AS_NEW, and the converter reads it back as the same event.
 */
final class ContinuedAsNewConversionTest extends TestCase
{
    public function testAContinuationCarriesTheNextTypeAndInput(): void
    {
        $attrs = $this->attributes();
        $attrs->setInput(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode(['cursor' => 42])));

        $converted = $this->convert($attrs);

        self::assertInstanceOf(WorkflowContinuedAsNew::class, $converted);
        self::assertSame('exec-1', $converted->executionId()->toString());
        self::assertSame('Next\\Type', $converted->nextWorkflowType());
        self::assertSame(['cursor' => 42], $converted->nextPayload());
        self::assertSame(
            ['task_queue' => 'durable', 'workflow_run_timeout_seconds' => 60.0, 'workflow_task_timeout_seconds' => 10.0],
            $converted->continuationMetadata(),
        );
    }

    public function testAContinuationWithoutInputHasAnEmptyPayload(): void
    {
        $converted = $this->convert($this->attributes());

        self::assertInstanceOf(WorkflowContinuedAsNew::class, $converted);
        self::assertSame('Next\\Type', $converted->nextWorkflowType());
        self::assertSame([], $converted->nextPayload());
    }

    private function attributes(): WorkflowExecutionContinuedAsNewEventAttributes
    {
        $attrs = new WorkflowExecutionContinuedAsNewEventAttributes();
        $attrs->setWorkflowType(new WorkflowType(['name' => 'Next\\Type']));
        $attrs->setTaskQueue(new TaskQueue(['name' => 'durable']));
        $attrs->setWorkflowRunTimeout(new Duration(['seconds' => 60]));
        $attrs->setWorkflowTaskTimeout(new Duration(['seconds' => 10]));

        return $attrs;
    }

    private function convert(WorkflowExecutionContinuedAsNewEventAttributes $attrs): ?object
    {
        $event = new HistoryEvent();
        $event->setEventId(9);
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CONTINUED_AS_NEW);
        $event->setWorkflowExecutionContinuedAsNewEventAttributes($attrs);

        return (new TemporalEventConverter(ExecutionId::fromString('exec-1')))->convert($event);
    }
}
