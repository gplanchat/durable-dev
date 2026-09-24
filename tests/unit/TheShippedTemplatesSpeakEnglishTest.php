<?php

declare(strict_types=1);

namespace unit\Gplanchat;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WA006 makes English the working language of everything this repository ships, and a template is
 * shipped twice over: it is read by whoever installs the package, and rendered to whoever opens the
 * screen. Both audiences were being spoken to in French.
 *
 * A French accented letter is the cheapest signal that a line was written in the wrong language.
 * It does not catch French written without accents, so a short list of French function words
 * backs it up; together they catch the drift that actually happens, and cost one pass over a
 * handful of files.
 */
final class TheShippedTemplatesSpeakEnglishTest extends TestCase
{
    private const ACCENTED = '/[àâäçéèêëîïôöùûüœÀÂÄÇÉÈÊËÎÏÔÖÙÛÜŒ]/u';

    /**
     * French written without accents slips past the letter check. These function words do not
     * occur in English prose or in code, so one of them on a line is the same signal.
     */
    private const FUNCTION_WORDS = '/\b(le|la|les|des|une|est|sont|dans|pour|avec|sans|tous|toutes|aussi|donc|mais|ou|où|pas|très|cette|ces|leur|leurs|notre|votre|chez|vers|depuis|jamais|toujours|encore|déjà|entre|selon|sinon|puis|alors|ainsi|afin|lorsque|quand)\b/iu';

    /**
     * @return iterable<string, array{string}>
     */
    public static function shippedTemplates(): iterable
    {
        $root = \dirname(__DIR__, 2);

        foreach (['src/DurableBundle/Resources/views', 'src/DurablePlugin/templates', 'src/DurableModule/view'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));

            /** @var \SplFileInfo $file */
            foreach ($files as $file) {
                if (!$file->isFile() || !\in_array($file->getExtension(), ['twig', 'phtml', 'xml'], true)) {
                    continue;
                }

                yield substr($file->getPathname(), \strlen($root) + 1) => [$file->getPathname()];
            }
        }
    }

    #[DataProvider('shippedTemplates')]
    public function testATemplateCarriesNoFrench(string $path): void
    {
        $offenders = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
            if (1 === preg_match(self::ACCENTED, $line) || 1 === preg_match(self::FUNCTION_WORDS, $line)) {
                $offenders[] = ($number + 1) . ': ' . trim($line);
            }
        }

        self::assertSame([], $offenders, 'French found in ' . basename($path));
    }
}
