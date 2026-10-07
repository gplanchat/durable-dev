<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Durable\Exception\DurableUpdateFailedException;
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
 * An update's completion lands on the update it completes, not on the last one accepted (#803).
 */
final class AnUpdateCompletionFindsItsOwnUpdateTest extends TestCase
{
    public function testTwoInterleavedUpdatesEachKeepTheirOwnResult(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->accepted(5, 'upd-a', 'approve'),
            $this->accepted(8, 'upd-b', 'reject'),
            $this->completed(9, 'upd-a', 5, $this->success('approved')),
            $this->completed(12, 'upd-b', 8, $this->success('rejected')),
        ]);

        self::assertSame('approved', $history->updateOutcome('upd-a')?->result);
        self::assertSame('rejected', $history->updateOutcome('upd-b')?->result);
    }

    public function testAFailedUpdateIsRecordedAsAFailure(): void
    {
        $failure = new Outcome();
        $failure->setFailure(new Failure(['message' => 'amount too high']));

        $history = TemporalExecutionHistory::fromEvents([
            $this->accepted(5, 'upd-a', 'approve'),
            $this->completed(9, 'upd-a', 5, $failure),
        ]);

        $outcome = $history->updateOutcome('upd-a');
        self::assertNotNull($outcome);
        self::assertInstanceOf(DurableUpdateFailedException::class, $outcome->failed);
        self::assertSame('Update "approve" failed: amount too high', $outcome->failed->getMessage());
    }

    public function testAnUpdateNotCompletedYetHasNoOutcome(): void
    {
        $history = TemporalExecutionHistory::fromEvents([$this->accepted(5, 'upd-a', 'approve')]);

        self::assertNull($history->updateOutcome('upd-a'));
    }

    public function testACompletionWithoutMetaFallsBackOnTheAcceptedEventId(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->accepted(5, 'upd-a', 'approve'),
            $this->accepted(8, 'upd-b', 'reject'),
            $this->completed(9, '', 5, $this->success('approved')),
        ]);

        self::assertSame('approved', $history->updateOutcome('upd-a')?->result);
        self::assertNull($history->updateOutcome('upd-b'));
    }

    public function testACompletionWhoseMetaIdMatchesNothingFallsBackOnTheAcceptedEventId(): void
    {
        $history = TemporalExecutionHistory::fromEvents([
            $this->accepted(5, 'upd-a', 'approve'),
            $this->accepted(8, 'upd-b', 'reject'),
            $this->completed(9, 'upd-unknown', 5, $this->success('approved')),
        ]);

        self::assertSame('approved', $history->updateOutcome('upd-a')?->result);
        self::assertNull($history->updateOutcome('upd-b'));
    }

    private function accepted(int $eventId, string $updateId, string $updateName): HistoryEvent
    {
        $request = new Request();
        $request->setMeta(new Meta(['update_id' => $updateId]));
        $request->setInput(new Input(['name' => $updateName]));

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
