<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Journal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Journal\JournalStateResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionSignaledEventAttributes;

/**
 * @internal
 */
#[CoversClass(JournalStateResolver::class)]
final class JournalStateResolverTest extends TestCase
{
    public function testEmptyHistoryReturnsEmptyArray(): void
    {
        $history = new History();

        $rows = JournalStateResolver::journalRowsFromHistory($history, 'durable-append');

        self::assertSame([], $rows);
    }

    public function testNonSignalEventsAreIgnored(): void
    {
        $history = new History();
        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED);
        $history->setEvents([$event]);

        $rows = JournalStateResolver::journalRowsFromHistory($history, 'durable-append');

        self::assertSame([], $rows);
    }

    public function testSignalEventWithWrongNameIsIgnored(): void
    {
        $history = $this->makeHistoryWithSignal('other-signal', [['type' => 'ExecutionStarted']]);

        $rows = JournalStateResolver::journalRowsFromHistory($history, 'durable-append');

        self::assertSame([], $rows);
    }

    public function testSignalEventWithMatchingNameReturnsDecodedRows(): void
    {
        $payload = ['type' => 'ExecutionStarted', 'executionId' => 'test-123'];
        $history = $this->makeHistoryWithSignal('durable-append', [$payload]);

        $rows = JournalStateResolver::journalRowsFromHistory($history, 'durable-append');

        self::assertCount(1, $rows);
        self::assertSame($payload, $rows[0]);
    }

    public function testMultipleSignalsWithMatchingNameReturnsAllRows(): void
    {
        $history = new History();
        $events = [];

        $events[] = $this->makeSignalHistoryEvent(
            'durable-append',
            [['type' => 'ExecutionStarted', 'executionId' => 'exec-1']],
        );
        $events[] = $this->makeSignalHistoryEvent(
            'other-signal',
            [['type' => 'ActivityScheduled']],
        );
        $events[] = $this->makeSignalHistoryEvent(
            'durable-append',
            [['type' => 'ActivityCompleted', 'executionId' => 'exec-1']],
        );

        $history->setEvents($events);

        $rows = JournalStateResolver::journalRowsFromHistory($history, 'durable-append');

        self::assertCount(2, $rows);
        self::assertSame('ExecutionStarted', $rows[0]['type']);
        self::assertSame('ActivityCompleted', $rows[1]['type']);
    }

    public function testSignalWithNullAttributesIsSkipped(): void
    {
        $history = new History();
        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED);
        // No attributes set → getWorkflowExecutionSignaledEventAttributes() returns null
        $history->setEvents([$event]);

        $rows = JournalStateResolver::journalRowsFromHistory($history, 'durable-append');

        self::assertSame([], $rows);
    }

    public function testNonArrayPayloadItemsAreSkipped(): void
    {
        $history = new History();
        $event = $this->makeSignalHistoryEvent('durable-append', ['a-string-not-an-array']);
        $history->setEvents([$event]);

        $rows = JournalStateResolver::journalRowsFromHistory($history, 'durable-append');

        self::assertSame([], $rows, 'Non-array payload items must be skipped.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param list<mixed> $payloadItems
     */
    private function makeHistoryWithSignal(string $signalName, array $payloadItems): History
    {
        $history = new History();
        $history->setEvents([$this->makeSignalHistoryEvent($signalName, $payloadItems)]);

        return $history;
    }

    /**
     * @param list<mixed> $payloadItems
     */
    private function makeSignalHistoryEvent(string $signalName, array $payloadItems): HistoryEvent
    {
        $payloads = new Payloads();
        $encoded = [];
        foreach ($payloadItems as $item) {
            $encoded[] = JsonPlainPayload::encode($item);
        }
        $payloads->setPayloads($encoded);

        $attrs = new WorkflowExecutionSignaledEventAttributes();
        $attrs->setSignalName($signalName);
        $attrs->setInput($payloads);

        $event = new HistoryEvent();
        $event->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED);
        $event->setWorkflowExecutionSignaledEventAttributes($attrs);

        return $event;
    }
}
