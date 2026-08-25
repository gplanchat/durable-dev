<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Activity\RetryLimit;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Command\V1\ScheduleActivityTaskCommandAttributes;

/**
 * The bridge must translate ActivityOptions into a Temporal RetryPolicy on the
 * ScheduleActivityTask command. Without it the server applies its default
 * (unbounded retries), so bounded attempts and non-retryable exceptions would
 * never take effect. This locks that translation.
 */
final class TemporalWorkflowCommandBufferRetryPolicyTest extends TestCase
{
    private function buffer(): TemporalWorkflowCommandBuffer
    {
        return new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'exec-1');
    }

    private function scheduledAttrs(TemporalWorkflowCommandBuffer $buffer): ScheduleActivityTaskCommandAttributes
    {
        $commands = $buffer->peek();
        self::assertCount(1, $commands);
        $attrs = $commands[0]->getScheduleActivityTaskCommandAttributes();
        self::assertInstanceOf(ScheduleActivityTaskCommandAttributes::class, $attrs);

        return $attrs;
    }

    public function testMaxAttemptsAndNonRetryableTypesBecomeARetryPolicy(): void
    {
        $options = (new ActivityOptions(
            RetryLimit::ofAttempts(5),
            nonRetryableExceptions: ['App\Domain\Exception\BusinessException'],
            timeouts: ActivityTimeouts::attempt(Duration::seconds(30.0)),
        ))->toMetadata();

        $buffer = $this->buffer();
        $buffer->scheduleActivity('act-1', 'delete_user', [], $options);

        $attrs = $this->scheduledAttrs($buffer);
        $policy = $attrs->getRetryPolicy();
        self::assertNotNull($policy, 'a RetryPolicy must be attached when options are present');
        self::assertSame(5, $policy->getMaximumAttempts());
        self::assertSame(
            ['App\Domain\Exception\BusinessException'],
            iterator_to_array($policy->getNonRetryableErrorTypes()),
        );

        // The 30s startToClose timeout must be preserved, not silently altered.
        self::assertSame(30, $attrs->getStartToCloseTimeout()?->getSeconds());
    }

    public function testUnlimitedAttemptsLeavesMaximumAttemptsUnset(): void
    {
        // maxAttempts 0 = unlimited: don't cap it, but still carry a policy.
        $options = (new ActivityOptions(RetryLimit::unlimited()))->toMetadata();

        $buffer = $this->buffer();
        $buffer->scheduleActivity('act-1', 'delete_user', [], $options);

        $policy = $this->scheduledAttrs($buffer)->getRetryPolicy();
        self::assertNotNull($policy);
        self::assertSame(0, $policy->getMaximumAttempts());
    }

    public function testNoOptionsMeansNoRetryPolicy(): void
    {
        // Backward compatible: an activity scheduled without options keeps the
        // server default (no explicit policy sent).
        $buffer = $this->buffer();
        $buffer->scheduleActivity('act-1', 'delete_user', [], []);

        self::assertNull($this->scheduledAttrs($buffer)->getRetryPolicy());
    }
}
