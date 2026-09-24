<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Journal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;

/**
 * Reads {@code durableExecutionId} from the memo of {@code WorkflowExecutionStarted}
 * (set by {@see \Gplanchat\Bridge\Temporal\WorkflowClient} via {@code StartWorkflowExecution}).
 */
final class JournalExecutionIdResolver
{
    public const MEMO_KEY_DURABLE_EXECUTION_ID = 'durableExecutionId';


    public static function durableExecutionIdFromStartedAttributes(
        \Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes $attr,
    ): string {
        $memo = $attr->getMemo();
        if (null === $memo) {
            throw new \RuntimeException(
                'Workflow history has no memo on WorkflowExecutionStarted; expected WorkflowClient.',
            );
        }
        $fields = $memo->getFields();
        if (!$fields->offsetExists(self::MEMO_KEY_DURABLE_EXECUTION_ID)) {
            throw new \RuntimeException(
                'Workflow history memo has no durableExecutionId; expected StartWorkflowExecution from WorkflowClient.',
            );
        }
        $payload = $fields->offsetGet(self::MEMO_KEY_DURABLE_EXECUTION_ID);
        $decoded = JsonPlainPayload::decode($payload);
        if (\is_string($decoded) && '' !== $decoded) {
            return $decoded;
        }

        throw new \RuntimeException('Memo durableExecutionId must decode to a non-empty string.');
    }
}
