<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Journal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\History;

/**
 * Lit {@code durableExecutionId} dans le memo de {@code WorkflowExecutionStarted}
 * (posé par {@see \Gplanchat\Bridge\Temporal\WorkflowClient} via {@code StartWorkflowExecution}).
 */
final class JournalExecutionIdResolver
{
    public const MEMO_KEY_DURABLE_EXECUTION_ID = 'durableExecutionId';


    public static function durableExecutionIdFromHistory(History $history): string
    {
        foreach ($history->getEvents() as $event) {
            if (EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED !== $event->getEventType()) {
                continue;
            }
            $attr = $event->getWorkflowExecutionStartedEventAttributes();
            if (null === $attr) {
                continue;
            }

            return self::durableExecutionIdFromStartedAttributes($attr);
        }

        throw new \RuntimeException(
            'Workflow history has no durableExecutionId memo on WorkflowExecutionStarted; expected StartWorkflowExecution from WorkflowClient.',
        );
    }

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
