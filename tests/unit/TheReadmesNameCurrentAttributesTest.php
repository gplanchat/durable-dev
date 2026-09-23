<?php

declare(strict_types=1);

namespace unit\Gplanchat;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Alpha8 gave every declaration attribute the `As` prefix — `#[ActivityMethod]` became
 * `#[AsActivityMethod]`, `#[Workflow]` became `#[AsWorkflow]` — and the READMEs, the shipped
 * `di.xml` and the bench configurations kept naming the old ones for months (#358). A reader who
 * copies `#[ActivityMethod]` from a README gets a class the loader never sees.
 *
 * `UPGRADE.md` is not scanned: it is where the old names legitimately live. Elsewhere a line may
 * name an old attribute only as the "before" of a migration — which is why a line is let through
 * when it also carries the new name or an arrow.
 */
final class TheReadmesNameCurrentAttributesTest extends TestCase
{
    private const OLD_NAMES = '/#\[(?:(?:Activity|Workflow|Query|Signal|Update)Method\b|Activity\(|Workflow\]|AsDurableActivity|ActivityInterface|WorkflowInterface)/';

    private const MIGRATION_LINE = '/#\[As(?:Activity|Workflow|Query|Signal|Update)|→|->/u';

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        $root = \dirname(__DIR__, 2);

        $files = array_merge(
            [$root . '/README.md', $root . '/laravel/README.md', $root . '/magento/README.md', $root . '/symfony/README.md'],
            glob($root . '/src/*/README.md') ?: [],
            glob($root . '/src/Bridge/*/README.md') ?: [],
            glob($root . '/src/DurableModule/etc/*.xml') ?: [],
            self::filesUnder($root . '/symfony/config', '/\.yaml$/'),
            self::filesUnder($root . '/magento/app/code', '/\.(?:php|xml)$/'),
        );

        foreach ($files as $path) {
            yield substr($path, \strlen($root) + 1) => [$path];
        }
    }

    #[DataProvider('documents')]
    public function testADocumentNamesNoPreAlpha8Attribute(string $path): void
    {
        $offenders = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
            if (1 === preg_match(self::OLD_NAMES, $line) && 1 !== preg_match(self::MIGRATION_LINE, $line)) {
                $offenders[] = ($number + 1) . ': ' . trim($line);
            }
        }

        self::assertSame([], $offenders, 'Pre-alpha8 attribute name found in ' . $path);
    }

    /**
     * @return list<string>
     */
    private static function filesUnder(string $directory, string $pattern): array
    {
        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (1 === preg_match($pattern, $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
