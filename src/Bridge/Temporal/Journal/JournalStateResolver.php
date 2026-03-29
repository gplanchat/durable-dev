<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Journal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\History;

/**
 * Replays workflow history to rebuild the durable journal (signal payloads only).
 *
 * @internal
 */
final class JournalStateResolver
{
    /**
     * @return list<array<string, mixed>> rows compatible with {@see \Gplanchat\Durable\Store\EventSerializer::deserialize}
     */
    public static function journalRowsFromHistory(History $history, string $signalAppend): array
    {
        $rows = [];
        foreach ($history->getEvents() as $event) {
            if (EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED !== $event->getEventType()) {
                continue;
            }
            $attr = $event->getWorkflowExecutionSignaledEventAttributes();
            if (null === $attr || $attr->getSignalName() !== $signalAppend) {
                continue;
            }
            $decoded = JsonPlainPayload::decodePayloads($attr->getInput());
            foreach ($decoded as $item) {
                if (\is_array($item)) {
                    /* @var array<string, mixed> $item */
                    $rows[] = $item;
                }
            }
        }

        return $rows;
    }
}
