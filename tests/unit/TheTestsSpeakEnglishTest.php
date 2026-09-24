<?php

declare(strict_types=1);

namespace unit\Gplanchat;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WA006 covers the tests too: their data prints in failure messages, their names in CI output, and
 * a contributor reads them to learn the API. Four sweeps translated them (#296, #378, #399, #400),
 * each missing what the previous grep could not see; this guard is what makes the last one stick.
 *
 * Same two signals as {@see TheRootDocumentsSpeakEnglishTest}. They cannot see French written
 * without accents or function words (`'paiements'`, `'bonjour'`): #400 found those by reading every
 * file, and a reviewer still has to. What the guard does hold is the line that can be caught.
 *
 * Some French is the point of a test. Each such line is listed below with its reason, matched by
 * a piece of its text rather than its line number, and an entry that no longer matches anything
 * fails too: an allowlist nobody prunes ends up hiding what it was never meant to.
 */
final class TheTestsSpeakEnglishTest extends TestCase
{
    private const ACCENTED = '/[àâäçéèêëîïôöùûüœÀÂÄÇÉÈÊËÎÏÔÖÙÛÜŒ]/u';

    private const FUNCTION_WORDS = '/\b(le|la|les|des|une|est|sont|dans|pour|avec|sans|tous|toutes|aussi|donc|mais|ou|où|pas|très|cette|ces|leur|leurs|notre|votre|chez|vers|depuis|jamais|toujours|encore|déjà|entre|selon|sinon|puis|alors|ainsi|afin|lorsque|quand)\b/iu';

    /**
     * path => [a piece of the allowed line => why it stays].
     */
    private const ALLOWED = [
        'tests/unit/Durable/Nexus/NexusEndpointTest.php' => [
            "'probé'" => 'the accent is the input: an endpoint name with a non-ASCII letter is refused',
        ],
        'tests/unit/Durable/Nexus/NexusServiceAndOperationNameTest.php' => [
            "'facturé'" => 'the accent is the input: a service name may carry a non-ASCII letter',
        ],
        'tests/integration/Temporal/NexusEndpointNameRulesTest.php' => [
            "'probé-nexus'" => 'the accent is the input: what the server does with a non-ASCII endpoint name',
        ],
        'tests/integration/Temporal/NexusServiceAndOperationNameRulesTest.php' => [
            "'opé'" => 'the accent is the input: what the server does with a non-ASCII operation name',
        ],
        'tests/unit/Durable/Observation/KeyPatternPayloadRedactorTest.php' => [
            "'é' is two bytes" => 'a two-byte character, so the truncation can be shown not to split it',
            "redact('ééé')" => 'same',
            "'é… (4 more bytes)'" => 'same',
        ],
        'src/DurablePlugin/tests/Integration/TheDashboardRendersARunHistoryTest.php' => [
            'Tableau de bord des workflows Durable' => 'asserts the French catalogue renders, the one product exception WA006 makes',
            'demandé' => 'same',
        ],
        'tests/unit/TheRootDocumentsSpeakEnglishTest.php' => [
            "'" . self::ACCENTED . "'" => 'a detector: the characters it looks for (the same constant as this guard)',
            "'" . self::FUNCTION_WORDS . "'" => 'a detector: the words it looks for',
        ],
        'tests/unit/TheShippedTemplatesSpeakEnglishTest.php' => [
            "'" . self::ACCENTED . "'" => 'a detector',
            "'" . self::FUNCTION_WORDS . "'" => 'a detector',
        ],
        'tests/unit/DurableBundle/TheConsoleSurfaceSpeaksEnglishTest.php' => [
            "'" . self::ACCENTED . "'" => 'a detector',
        ],
        'tests/unit/Durable/Testing/TheExportedTestHelpersSpeakEnglishTest.php' => [
            "'" . self::ACCENTED . "'" => 'a detector',
        ],
        'tests/unit/DurableBundle/Profiler/TheProfilerSpeaksEnglishTest.php' => [
            "preg_match('/[àâçéèêëîïôùûœÀÂÇÉÈÊÎÔÛŒ]/u'" => 'a detector',
        ],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function scannedTestFiles(): iterable
    {
        $root = \dirname(__DIR__, 2);

        foreach (self::scannedFiles($root) as $relative) {
            yield $relative => [$relative];
        }
    }

    #[DataProvider('scannedTestFiles')]
    public function testATestFileCarriesNoFrench(string $relative): void
    {
        $allowed = self::ALLOWED[$relative] ?? [];
        $offenders = [];
        foreach (self::frenchLines($relative) as $number => $line) {
            if (self::stillFrench($line, array_keys($allowed))) {
                $offenders[] = $number . ': ' . trim($line);
            }
        }

        self::assertSame([], $offenders, 'French found in ' . $relative . ' (translate it, or add it to ALLOWED with the reason it has to stay)');
    }

    public function testAnAllowedPieceDoesNotCoverTheRestOfItsLine(): void
    {
        // Review of #538: the whole line used to pass as soon as it held a needle, so French added
        // next to an allowed input hid behind it.
        $needles = ["'probé'"];

        self::assertFalse(self::stillFrench("        yield 'accented letter' => ['probé'];", $needles));
        self::assertTrue(self::stillFrench("        yield 'accented letter' => ['probé']; // refusé pour le client", $needles));
        self::assertTrue(self::stillFrench("        yield 'accented letter' => ['probé']; // pas de charge", $needles), 'French without accents too');
    }

    public function testEveryAllowedLineStillExists(): void
    {
        $stale = [];
        foreach (self::ALLOWED as $relative => $needles) {
            $lines = self::frenchLines($relative);
            foreach (array_keys($needles) as $needle) {
                if ([] === array_filter($lines, static fn(string $line): bool => str_contains($line, $needle))) {
                    $stale[] = $relative . ': ' . $needle;
                }
            }
        }

        self::assertSame([], $stale, 'These ALLOWED entries match no French line any more: remove them');
    }

    /**
     * Whether a line still trips a signal once its allowed pieces are taken out: an allowed input
     * excuses itself, not French written next to it.
     *
     * @param list<string> $needles
     */
    private static function stillFrench(string $line, array $needles): bool
    {
        $rest = str_replace($needles, '', $line);

        return 1 === preg_match(self::ACCENTED, $rest) || 1 === preg_match(self::FUNCTION_WORDS, $rest);
    }

    /**
     * @return array<int, string> line number => line, for the lines that trip a signal
     */
    private static function frenchLines(string $relative): array
    {
        $found = [];
        foreach (file(\dirname(__DIR__, 2) . '/' . $relative, \FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
            if (1 === preg_match(self::ACCENTED, $line) || 1 === preg_match(self::FUNCTION_WORDS, $line)) {
                $found[$index + 1] = $line;
            }
        }

        return $found;
    }

    /**
     * Every PHP source under the root test tree and the packages' own ones (the benches under
     * symfony/, laravel/, sylius/ and magento/ are applications, not this repository's tests).
     * This file is left out: it has to spell what it looks for.
     *
     * @return list<string>
     */
    private static function scannedFiles(string $root): array
    {
        $files = [];
        foreach (['tests', ...(glob($root . '/src/*/tests', \GLOB_ONLYDIR) ?: [])] as $dir) {
            $dir = str_starts_with($dir, $root) ? $dir : $root . '/' . $dir;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if ((str_ends_with($path, '.php') || str_ends_with($path, '.inc')) && $path !== __FILE__) {
                    $files[] = substr($path, \strlen($root) + 1);
                }
            }
        }
        sort($files);

        return $files;
    }
}
