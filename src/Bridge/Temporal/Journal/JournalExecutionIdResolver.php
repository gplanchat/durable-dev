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
    private function __construct() {}

    public const MEMO_KEY_DURABLE_EXECUTION_ID = 'durableExecutionId';

    /** What a suspended run last waited on, in the core's words (#514), upserted at each suspension. */
    public const MEMO_KEY_DURABLE_WAITING_ON = 'durableWaitingOn';

    /**
     * The execution id a memo carries, or `null` when it carries none Durable wrote.
     *
     * A payload that is not JSON counts as none here: this reads the visibility of any workflow.
     */
    public static function fromMemo(?\Temporal\Api\Common\V1\Memo $memo): ?string
    {
        try {
            return self::read($memo);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * The execution id on `WorkflowExecutionStarted`, or `null` when there is none (#799).
     *
     * @internal the worker's history reads the id through this; call {@see durableExecutionIdFromStartedAttributes()}
     *
     * @throws \JsonException when the payload is not JSON
     */
    public static function fromStartedAttributes(
        \Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes $attr,
    ): ?string {
        return self::read($attr->getMemo());
    }

    /**
     * @throws \RuntimeException when the started event carries no execution id
     * @throws \JsonException    when the payload is not JSON
     */
    public static function durableExecutionIdFromStartedAttributes(
        \Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes $attr,
    ): string {
        return self::fromStartedAttributes($attr) ?? throw new \RuntimeException(
            'WorkflowExecutionStarted carries no durableExecutionId memo that decodes to a non-empty string; expected StartWorkflowExecution from WorkflowClient.',
        );
    }

    /**
     * The one reading of the memo field: no memo, no field, or a value that is not a non-empty
     * string give `null`; a payload that is not JSON throws.
     *
     * @throws \JsonException
     */
    private static function read(?\Temporal\Api\Common\V1\Memo $memo): ?string
    {
        $fields = $memo?->getFields();
        if (null === $fields || !$fields->offsetExists(self::MEMO_KEY_DURABLE_EXECUTION_ID)) {
            return null;
        }
        $decoded = JsonPlainPayload::decode($fields->offsetGet(self::MEMO_KEY_DURABLE_EXECUTION_ID));

        return \is_string($decoded) && '' !== $decoded ? $decoded : null;
    }
}
