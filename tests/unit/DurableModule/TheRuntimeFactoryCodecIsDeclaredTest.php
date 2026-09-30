<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The module declares no `codec` argument (DUR055): a declaration of its own, `null` included,
 * would beat the one a shop sets whenever the shop's module loads first, and the codec would be
 * lost without a word. The shop's `di.xml` is the only place that names it. Magento is not in the
 * root graph, so this reads the declaration.
 */
final class TheRuntimeFactoryCodecIsDeclaredTest extends TestCase
{
    public function testDiXmlLeavesTheCodecArgumentToTheShop(): void
    {
        $di = simplexml_load_file(__DIR__ . '/../../../src/DurableModule/etc/di.xml');
        self::assertNotFalse($di);

        $codec = $di->xpath(\sprintf('//type[@name="%s"]/arguments/argument[@name="codec"]', RuntimeFactory::class));
        self::assertSame([], $codec);
    }
}
