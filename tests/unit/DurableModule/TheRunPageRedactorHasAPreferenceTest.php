<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableModule;

use Gplanchat\Durable\Observation\KeyPatternPayloadRedactor;
use Gplanchat\Durable\Observation\PayloadRedactorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Magento's run page takes the redactor through the ObjectManager: the module prefers #488's key
 * pattern, and an application replaces it with a preference of its own (#507).
 */
final class TheRunPageRedactorHasAPreferenceTest extends TestCase
{
    public function testDiXmlPrefersTheKeyPatternRedactor(): void
    {
        $di = simplexml_load_file(__DIR__ . '/../../../src/DurableModule/etc/di.xml');
        self::assertNotFalse($di);

        $preferences = [];
        foreach ($di->preference as $preference) {
            $preferences[(string) $preference['for']] = (string) $preference['type'];
        }

        // Through a virtual type, which `setup:di:compile` accepts where it refuses a class of
        // `gplanchat/durable` (#725): the preference must still resolve to the key pattern.
        $virtualTypes = [];
        foreach ($di->virtualType as $virtualType) {
            $virtualTypes[(string) $virtualType['name']] = (string) $virtualType['type'];
        }
        $preferred = $preferences[PayloadRedactorInterface::class] ?? null;

        self::assertSame(KeyPatternPayloadRedactor::class, $virtualTypes[$preferred] ?? $preferred);
    }

    public function testTheDetailBlockAsksForIt(): void
    {
        // The block extends Magento's Template, absent from this suite: its constructor is read,
        // and PHPStan checks it against the real Magento classes in CI (phpstan-magento).
        $block = (string) file_get_contents(__DIR__ . '/../../../src/DurableModule/Block/Adminhtml/ProcessDetail.php');

        self::assertMatchesRegularExpression('/private readonly PayloadRedactorInterface \$redactor/', $block);
        self::assertStringContainsString('RunTimeline::of(', $block);
        self::assertMatchesRegularExpression('/RunTimeline::of\([^;]*\$this->redactor\s*,?\s*\)/s', $block);
    }
}
