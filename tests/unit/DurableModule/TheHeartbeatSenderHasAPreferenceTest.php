<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableModule\Runtime\SharedActivityHeartbeatSender;
use PHPUnit\Framework\TestCase;

/**
 * The activities page lets an activity inject the heartbeat sender through its constructor. The
 * ObjectManager builds such an activity only with a preference for the interface, and the factory
 * gets the same instance only through an explicit argument. Magento is not in the root graph, so
 * this reads the declaration. The "Magento module (it really boots)" CI job resolves both when
 * `durable:demo` receives the factory; it does not exercise the Temporal path.
 */
final class TheHeartbeatSenderHasAPreferenceTest extends TestCase
{
    public function testDiXmlPrefersTheSharedSenderAndHandsItToTheFactory(): void
    {
        $di = simplexml_load_file(__DIR__ . '/../../../src/DurableModule/etc/di.xml');
        self::assertNotFalse($di);

        $preferences = [];
        foreach ($di->preference as $preference) {
            $preferences[(string) $preference['for']] = (string) $preference['type'];
        }

        // No longer the bare no-op (#510): the shared sender is one until the Temporal worker exists.
        self::assertSame(SharedActivityHeartbeatSender::class, $preferences[ActivityHeartbeatSenderInterface::class] ?? null);

        // An optional argument is not autowired: without this, the factory would get null and the
        // activities would keep the no-op on Temporal.
        $heartbeat = $di->xpath(\sprintf('//type[@name="%s"]/arguments/argument[@name="heartbeat"]', RuntimeFactory::class));
        self::assertSame(ActivityHeartbeatSenderInterface::class, trim((string) ($heartbeat[0] ?? '')));
    }
}
