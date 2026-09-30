<?php

declare(strict_types=1);

namespace unit\DurableModule;

use PHPUnit\Framework\TestCase;

/**
 * `setup:di:compile` only compiles Magento components: modules and registered libraries.
 * `gplanchat/durable` is neither, so a preference aimed straight at one of its classes stops the
 * compilation with "has not been included in dependency injection compilation" (#725, found on a
 * Mage-OS 2.2.0 install). A virtual type is exempt: the compiler reflects its class on demand.
 */
final class ThePreferencesSurviveDiCompileTest extends TestCase
{
    public function testEveryPreferenceTargetsTheModuleOrAVirtualType(): void
    {
        $di = simplexml_load_file(__DIR__ . '/../../../src/DurableModule/etc/di.xml');
        self::assertNotFalse($di);

        $virtualTypes = [];
        foreach ($di->virtualType as $virtualType) {
            $virtualTypes[] = (string) $virtualType['name'];
        }

        $uncompiled = [];
        foreach ($di->preference as $preference) {
            $type = (string) $preference['type'];
            if (!str_starts_with($type, 'Gplanchat\\DurableModule\\') && !\in_array($type, $virtualTypes, true)) {
                $uncompiled[] = $type;
            }
        }

        self::assertSame([], $uncompiled);
    }
}
