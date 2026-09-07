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
 * It does not catch French written without accents — no regex does — but it catches the drift that
 * actually happens, and it costs one pass over a handful of files.
 */
final class TheShippedTemplatesSpeakEnglishTest extends TestCase
{
    private const ACCENTED = '/[àâäçéèêëîïôöùûüœÀÂÄÇÉÈÊËÎÏÔÖÙÛÜŒ]/u';

    /**
     * @return iterable<string, array{string}>
     */
    public static function shippedTemplates(): iterable
    {
        $root = \dirname(__DIR__, 2);

        foreach (['src/DurableBundle/Resources/views', 'src/DurablePlugin/Resources/views', 'src/DurableModule/view'] as $dir) {
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
            if (1 === preg_match(self::ACCENTED, $line)) {
                $offenders[] = ($number + 1) . ': ' . trim($line);
            }
        }

        self::assertSame([], $offenders, 'French found in ' . basename($path));
    }
}
