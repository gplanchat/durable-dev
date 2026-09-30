<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Gplanchat\Bridge\Dbal\Messenger\LockActivityAttemptClaim;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The DBAL backend's stand-in for Temporal's refusal of a second start of one attempt (#590).
 */
#[CoversClass(LockActivityAttemptClaim::class)]
final class LockActivityAttemptClaimTest extends TestCase
{
    public function testAnAttemptIsClaimedOnceUntilReleased(): void
    {
        $claims = new LockActivityAttemptClaim(new LockFactory(new InMemoryStore()));

        $release = $claims->claim(ExecutionId::fromString('exec-1'), 'act-1', 2);
        self::assertNotNull($release);
        self::assertNull($claims->claim(ExecutionId::fromString('exec-1'), 'act-1', 2), 'a copy delivered meanwhile is refused');
        self::assertNotNull($claims->claim(ExecutionId::fromString('exec-1'), 'act-1', 3), 'another attempt is not the same claim');

        $release();
        self::assertNotNull($claims->claim(ExecutionId::fromString('exec-1'), 'act-1', 2), 'released, the attempt can be claimed again');
    }
}
