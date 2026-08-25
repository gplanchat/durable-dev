<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Worker\TemporalPolicyMapper;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Gplanchat\Durable\WorkflowStartOptions;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\ParentClosePolicy as TemporalParentClosePolicy;
use Temporal\Api\Enums\V1\WorkflowIdReusePolicy as TemporalIdReusePolicy;

/**
 * Racine et enfant décrivent les mêmes réglages : ils ne doivent pas les traduire différemment.
 */
final class TemporalPolicyMapperTest extends TestCase
{
    public function testPoliciesMapFromBothEnumAndWireValue(): void
    {
        self::assertSame(
            TemporalParentClosePolicy::PARENT_CLOSE_POLICY_ABANDON,
            TemporalPolicyMapper::parentClosePolicy(ParentClosePolicy::Abandon),
        );
        self::assertSame(
            TemporalParentClosePolicy::PARENT_CLOSE_POLICY_REQUEST_CANCEL,
            TemporalPolicyMapper::parentClosePolicy('request_cancel'),
        );
        self::assertSame(
            TemporalParentClosePolicy::PARENT_CLOSE_POLICY_TERMINATE,
            TemporalPolicyMapper::parentClosePolicy(null),
        );

        self::assertSame(
            TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_REJECT_DUPLICATE,
            TemporalPolicyMapper::idReusePolicy(WorkflowIdReusePolicy::RejectDuplicate),
        );
        self::assertSame(
            TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_ALLOW_DUPLICATE_FAILED_ONLY,
            TemporalPolicyMapper::idReusePolicy('inconnu'),
        );
    }

    public function testDurationSplitsSecondsAndNanos(): void
    {
        $duration = TemporalPolicyMapper::duration(1.5);

        self::assertSame(1, $duration->getSeconds());
        self::assertSame(500_000_000, $duration->getNanos());
    }

    public function testStartOptionsCarryCronAndUseTheSameMetadataKeysAsChildren(): void
    {
        $metadata = (new WorkflowStartOptions(
            cronSchedule: '@every 5m',
            taskQueue: 'dedicated',
            workflowRunTimeoutSeconds: 30.0,
            workflowIdReusePolicy: WorkflowIdReusePolicy::RejectDuplicate,
        ))->toStartMetadata();

        self::assertSame('@every 5m', $metadata['cron_schedule']);
        self::assertSame('dedicated', $metadata['task_queue']);
        self::assertSame(30.0, $metadata['workflow_run_timeout_seconds']);
        self::assertSame('reject_duplicate', $metadata['workflow_id_reuse_policy']);
    }

    public function testDefaultStartOptionsCarryNoCron(): void
    {
        self::assertArrayNotHasKey('cron_schedule', WorkflowStartOptions::defaults()->toStartMetadata());
    }
}
