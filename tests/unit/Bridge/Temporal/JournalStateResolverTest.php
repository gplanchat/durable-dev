<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Journal\JournalStateResolver;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
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
    #[Test]
    #[TestDox('It should replay append signals in order')]
    public function itShouldReplayAppendSignalsInOrder(): void
    {
        $signal = TemporalConnection::DEFAULT_SIGNAL_APPEND;

        $payload1 = JsonPlainPayload::encode(['execution_id' => 'e1', 'event_type' => 't1', 'payload' => ['a' => 1]]);
        $payload2 = JsonPlainPayload::encode(['execution_id' => 'e1', 'event_type' => 't2', 'payload' => ['b' => 2]]);

        $attr1 = new WorkflowExecutionSignaledEventAttributes([
            'signal_name' => $signal,
            'input' => JsonPlainPayload::singlePayloads($payload1),
        ]);
        $ev1 = new HistoryEvent([
            'event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED,
            'workflow_execution_signaled_event_attributes' => $attr1,
        ]);

        $attr2 = new WorkflowExecutionSignaledEventAttributes([
            'signal_name' => $signal,
            'input' => JsonPlainPayload::singlePayloads($payload2),
        ]);
        $ev2 = new HistoryEvent([
            'event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED,
            'workflow_execution_signaled_event_attributes' => $attr2,
        ]);

        $history = new History(['events' => [$ev1, $ev2]]);

        $rows = JournalStateResolver::journalRowsFromHistory($history, $signal);

        self::assertSame(
            [
                ['execution_id' => 'e1', 'event_type' => 't1', 'payload' => ['a' => 1]],
                ['execution_id' => 'e1', 'event_type' => 't2', 'payload' => ['b' => 2]],
            ],
            $rows,
        );
    }
}
