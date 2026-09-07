<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Testing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The conformance test cases and the spies are exported: a consumer who writes a backend extends
 * them, and it is their failure messages that consumer reads when a run goes red. That puts them
 * under WA006 next to the profiler panel.
 *
 * Only lines that mention an assertion are read. A fixture may legitimately carry an accented
 * character — `EventStoreConformanceTestCase` has one on purpose, to prove that a payload survives
 * the round trip byte for byte — and a guard that failed on it would be asking for the fixture to
 * be weakened.
 */
final class TheExportedTestHelpersSpeakEnglishTest extends TestCase
{
    private const ACCENTED = '/[àâäçéèêëîïôöùûüœÀÂÄÇÉÈÊËÎÏÔÖÙÛÜŒ]/u';

    /**
     * @return iterable<string, array{string}>
     */
    public static function exportedHelpers(): iterable
    {
        $root = \dirname(__DIR__, 4);

        foreach (['src/Durable/Testing', 'src/DurableBundle/Testing'] as $dir) {
            foreach (glob($root . '/' . $dir . '/*.php') ?: [] as $path) {
                yield substr($path, \strlen($root) + 1) => [$path];
            }
        }
    }

    #[DataProvider('exportedHelpers')]
    public function testAnAssertionExplainsItselfInEnglish(string $path): void
    {
        $offenders = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
            if (1 === preg_match('/assert/i', $line) && 1 === preg_match(self::ACCENTED, $line)) {
                $offenders[] = ($number + 1) . ': ' . trim($line);
            }
        }

        self::assertSame([], $offenders, 'French found in ' . basename($path));
    }
}
