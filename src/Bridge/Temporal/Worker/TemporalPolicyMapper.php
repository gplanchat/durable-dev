<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Google\Protobuf\Duration;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Temporal\Api\Enums\V1\ParentClosePolicy as TemporalParentClosePolicy;
use Temporal\Api\Enums\V1\WorkflowIdReusePolicy as TemporalIdReusePolicy;

/**
 * Conversions des options durables vers leurs équivalents protobuf.
 *
 * Partagées par le buffer de commandes (workflows enfants) et le client (démarrage racine) :
 * racine et enfant décrivent les mêmes réglages, ils ne doivent pas les traduire différemment.
 */
final class TemporalPolicyMapper
{
    private function __construct()
    {
    }

    public static function parentClosePolicy(mixed $policy): int
    {
        $value = $policy instanceof ParentClosePolicy
            ? $policy
            : ParentClosePolicy::tryFrom((string) (\is_scalar($policy) ? $policy : ''));

        return match ($value) {
            ParentClosePolicy::Abandon => TemporalParentClosePolicy::PARENT_CLOSE_POLICY_ABANDON,
            ParentClosePolicy::RequestCancel => TemporalParentClosePolicy::PARENT_CLOSE_POLICY_REQUEST_CANCEL,
            default => TemporalParentClosePolicy::PARENT_CLOSE_POLICY_TERMINATE,
        };
    }

    public static function idReusePolicy(mixed $policy): int
    {
        $value = $policy instanceof WorkflowIdReusePolicy
            ? $policy
            : WorkflowIdReusePolicy::tryFrom((string) (\is_scalar($policy) ? $policy : ''));

        return match ($value) {
            WorkflowIdReusePolicy::AllowDuplicate => TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_ALLOW_DUPLICATE,
            WorkflowIdReusePolicy::RejectDuplicate => TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_REJECT_DUPLICATE,
            default => TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_ALLOW_DUPLICATE_FAILED_ONLY,
        };
    }

    public static function duration(float $seconds): Duration
    {
        $duration = new Duration();
        $whole = (int) floor($seconds);
        $nanos = (int) round(($seconds - $whole) * 1_000_000_000);
        if ($nanos >= 1_000_000_000) {
            ++$whole;
            $nanos -= 1_000_000_000;
        }
        $duration->setSeconds($whole);
        $duration->setNanos($nanos);

        return $duration;
    }
}
