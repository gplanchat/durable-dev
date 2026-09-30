<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Queue\ActivityAttemptLock;
use Gplanchat\Durable\ExecutionId;
use Illuminate\Cache\ArrayStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Laravel's stand-in for Temporal's refusal of a second start of one attempt (#590), over the same
 * cache lock as the resume lock.
 */
#[CoversClass(ActivityAttemptLock::class)]
final class ActivityAttemptLockTest extends TestCase
{
    public function testAnAttemptIsClaimedOnceUntilReleased(): void
    {
        $claims = new ActivityAttemptLock(new ArrayStore());

        $release = $claims->claim(ExecutionId::fromString('exec-1'), 'act-1', 2);
        self::assertNotNull($release);
        self::assertNull($claims->claim(ExecutionId::fromString('exec-1'), 'act-1', 2), 'a copy delivered meanwhile is refused');
        self::assertNotNull($claims->claim(ExecutionId::fromString('exec-1'), 'act-1', 3), 'another attempt is not the same claim');

        $release();
        self::assertNotNull($claims->claim(ExecutionId::fromString('exec-1'), 'act-1', 2), 'released, the attempt can be claimed again');
    }
}
