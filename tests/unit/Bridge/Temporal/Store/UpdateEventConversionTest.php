<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Store;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Store\TemporalEventConverter;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionUpdateAcceptedEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionUpdateCompletedEventAttributes;
use Temporal\Api\Update\V1\Input;
use Temporal\Api\Update\V1\Meta;
use Temporal\Api\Update\V1\Outcome;
use Temporal\Api\Update\V1\Request;

/**
 * An accepted update and its completion are one WorkflowUpdateHandled, as the journal backends write it (#1008).
 */
final class UpdateEventConversionTest extends TestCase
{
    public function testAnAcceptedThenCompletedUpdateEmitsOneEventAtTheCompletion(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));

        self::assertNull($converter->convert($this->accepted(5, 'upd-a', 'approve', ['amount' => 10])));
        $handled = $converter->convert($this->completed(9, 'upd-a', 5, $this->success('approved')));

        self::assertInstanceOf(WorkflowUpdateHandled::class, $handled);
        self::assertSame('approve', $handled->updateName());
        self::assertSame(['amount' => 10], $handled->arguments());
        self::assertSame('approved', $handled->result());
        self::assertNull($handled->failure());
    }

    public function testAFailedUpdateCarriesItsFailure(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));
        $outcome = new Outcome();
        $outcome->setFailure(new Failure(['message' => 'amount too high']));

        $converter->convert($this->accepted(5, 'upd-a', 'approve', ['amount' => 99]));
        $handled = $converter->convert($this->completed(9, 'upd-a', 5, $outcome));

        self::assertInstanceOf(WorkflowUpdateHandled::class, $handled);
        self::assertSame(['amount' => 99], $handled->arguments());
        self::assertSame('amount too high', $handled->failure()?->message);
        self::assertNull($handled->result());
    }

    public function testInterleavedUpdatesArePairedByIdNotByPosition(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));

        $converter->convert($this->accepted(5, 'upd-a', 'approve', ['n' => 1]));
        $converter->convert($this->accepted(8, 'upd-b', 'reject', ['n' => 2]));
        $b = $converter->convert($this->completed(9, 'upd-b', 8, $this->success('rejected')));
        $a = $converter->convert($this->completed(12, 'upd-a', 5, $this->success('approved')));

        self::assertInstanceOf(WorkflowUpdateHandled::class, $a);
        self::assertInstanceOf(WorkflowUpdateHandled::class, $b);
        self::assertSame(['reject', ['n' => 2], 'rejected'], [$b->updateName(), $b->arguments(), $b->result()]);
        self::assertSame(['approve', ['n' => 1], 'approved'], [$a->updateName(), $a->arguments(), $a->result()]);
    }

    public function testACompletionWithoutMetaFallsBackOnTheAcceptedEventId(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));

        $converter->convert($this->accepted(5, 'upd-a', 'approve', ['n' => 1]));
        $converter->convert($this->accepted(8, 'upd-b', 'reject', ['n' => 2]));
        $handled = $converter->convert($this->completed(9, '', 8, $this->success('rejected')));

        self::assertInstanceOf(WorkflowUpdateHandled::class, $handled);
        self::assertSame('reject', $handled->updateName());
    }

    public function testAnUpdateStillPendingEmitsNothingAndKeepsItsArguments(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));

        self::assertNull($converter->convert($this->accepted(5, 'upd-a', 'approve', ['n' => 1])));
        self::assertNull($converter->convert($this->accepted(6, 'upd-b', 'reject', ['n' => 2])));
        $handled = $converter->convert($this->completed(9, 'upd-a', 5, $this->success('ok')));

        self::assertInstanceOf(WorkflowUpdateHandled::class, $handled);
        self::assertSame(['n' => 1], $handled->arguments());
    }

    public function testACompletionWithoutAcceptedUpdateEmitsNothing(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));

        self::assertNull($converter->convert($this->completed(9, 'upd-x', 5, $this->success('ok'))));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function accepted(int $eventId, string $updateId, string $updateName, array $arguments = []): HistoryEvent
    {
        $input = new Input(['name' => $updateName]);
        $input->setArgs(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($arguments)));
        $request = new Request();
        $request->setMeta(new Meta(['update_id' => $updateId]));
        $request->setInput($input);

        $attrs = new WorkflowExecutionUpdateAcceptedEventAttributes();
        $attrs->setProtocolInstanceId('pid-' . $eventId);
        $attrs->setAcceptedRequest($request);

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED);
        $event->setEventId($eventId);
        $event->setWorkflowExecutionUpdateAcceptedEventAttributes($attrs);

        return $event;
    }

    private function completed(int $eventId, string $updateId, int $acceptedEventId, Outcome $outcome): HistoryEvent
    {
        $attrs = new WorkflowExecutionUpdateCompletedEventAttributes();
        if ('' !== $updateId) {
            $attrs->setMeta(new Meta(['update_id' => $updateId]));
        }
        $attrs->setAcceptedEventId($acceptedEventId);
        $attrs->setOutcome($outcome);

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_COMPLETED);
        $event->setEventId($eventId);
        $event->setWorkflowExecutionUpdateCompletedEventAttributes($attrs);

        return $event;
    }

    private function success(mixed $result): Outcome
    {
        $outcome = new Outcome();
        $outcome->setSuccess(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($result)));

        return $outcome;
    }
}
