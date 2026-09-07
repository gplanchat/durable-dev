<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Google\Protobuf\Duration;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\SearchAttributes;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Gplanchat\Durable\WorkflowTimeouts;
use Temporal\Api\Common\V1\SearchAttributes as TemporalSearchAttributes;
use Temporal\Api\Enums\V1\ParentClosePolicy as TemporalParentClosePolicy;
use Temporal\Api\Enums\V1\WorkflowIdReusePolicy as TemporalIdReusePolicy;

/**
 * Conversions from the durable options to their protobuf equivalents.
 *
 * Shared by the command buffer (child workflows) and the client (root start): root and child
 * describe the same settings, they must not translate them differently.
 *
 * The signatures used to accept `mixed` — not out of flexibility, but because the values crossed
 * an array before arriving. Now that they cross the port typed, the `match` is exhaustive and the
 * compiler answers for it.
 */
final class TemporalPolicyMapper
{
    private function __construct() {}

    public static function parentClosePolicy(ParentClosePolicy $policy): int
    {
        return match ($policy) {
            ParentClosePolicy::Abandon => TemporalParentClosePolicy::PARENT_CLOSE_POLICY_ABANDON,
            ParentClosePolicy::RequestCancel => TemporalParentClosePolicy::PARENT_CLOSE_POLICY_REQUEST_CANCEL,
            ParentClosePolicy::Terminate => TemporalParentClosePolicy::PARENT_CLOSE_POLICY_TERMINATE,
        };
    }

    public static function idReusePolicy(WorkflowIdReusePolicy $policy): int
    {
        return match ($policy) {
            WorkflowIdReusePolicy::AllowDuplicate => TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_ALLOW_DUPLICATE,
            WorkflowIdReusePolicy::RejectDuplicate => TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_REJECT_DUPLICATE,
            WorkflowIdReusePolicy::AllowDuplicateFailedOnly => TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_ALLOW_DUPLICATE_FAILED_ONLY,
        };
    }

    /**
     * Sets the time bounds on any message that accepts them — start request, child command,
     * continue-as-new command: they expose the same setters, and must not translate the same
     * options differently.
     *
     * @param object $target protobuf message exposing setWorkflowExecutionTimeout /
     *                       setWorkflowRunTimeout / setWorkflowTaskTimeout
     */
    public static function applyWorkflowTimeouts(WorkflowTimeouts $timeouts, object $target): void
    {
        foreach ([
            'setWorkflowExecutionTimeout' => $timeouts->execution,
            'setWorkflowRunTimeout' => $timeouts->run,
            'setWorkflowTaskTimeout' => $timeouts->task,
        ] as $setter => $bound) {
            if (null !== $bound && method_exists($target, $setter)) {
                $target->{$setter}(self::duration($bound->toSeconds()));
            }
        }
    }

    /**
     * Sets the search attributes on a message that accepts them.
     *
     * The type travels with each value in the payload metadata. The server actually applies the
     * one from its own registry — but announcing it makes the intent readable to whoever inspects
     * the history.
     */
    public static function applySearchAttributes(SearchAttributes $attributes, object $target): void
    {
        if ($attributes->isEmpty() || !method_exists($target, 'setSearchAttributes')) {
            return;
        }

        $message = new TemporalSearchAttributes();
        $fields = $message->getIndexedFields();
        foreach ($attributes->toValues() as $name => $value) {
            // The type travels with the value in the payload metadata.
            $fields[$name] = JsonPlainPayload::encodeWithMetadata($value, [
                'type' => (string) $attributes->typeOf($name)?->value,
            ]);
        }
        $message->setIndexedFields($fields);

        $target->setSearchAttributes($message);
    }

    public static function duration(float $seconds): Duration
    {
        $duration = new Duration();
        $whole = (int) floor($seconds);
        $nanos = (int) round(($seconds - (float) $whole) * 1_000_000_000.0);
        if ($nanos >= 1_000_000_000) {
            ++$whole;
            $nanos -= 1_000_000_000;
        }
        $duration->setSeconds($whole);
        $duration->setNanos($nanos);

        return $duration;
    }
}
