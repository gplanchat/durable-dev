<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use PHPUnit\Framework\TestCase;

/**
 * The activities page lets an activity inject the heartbeat sender through its constructor. The
 * ObjectManager builds such an activity only with a preference for the interface. Magento is not
 * in the root graph, so this reads the declaration; the Mage-OS bench job builds it for real.
 */
final class TheHeartbeatSenderHasAPreferenceTest extends TestCase
{
    public function testDiXmlPrefersTheNoOpSenderForTheInterface(): void
    {
        $di = simplexml_load_file(__DIR__ . '/../../../src/DurableModule/etc/di.xml');
        self::assertNotFalse($di);

        $preferences = [];
        foreach ($di->preference as $preference) {
            $preferences[(string) $preference['for']] = (string) $preference['type'];
        }

        self::assertSame(NullActivityHeartbeatSender::class, $preferences[ActivityHeartbeatSenderInterface::class] ?? null);
    }
}
