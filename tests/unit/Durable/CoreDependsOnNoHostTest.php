<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use PHPUnit\Framework\TestCase;

/**
 * The core depends on no host, and this guard exists because it once did.
 *
 * `gplanchat/durable` requires neither the Symfony bundle nor any bridge: that is the component's
 * promise, and what makes "the same workflow runs everywhere" true rather than aspirational. A
 * single line had broken it — `InMemoryWorkflowRunner` importing
 * `Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator` — and nothing said so: under
 * Symfony the bundle is there, so everything works. The breakage only shows on a host that does
 * not install it, at the moment of a resume, as a fatal class-not-found error. Magento found it by
 * replaying a command killed halfway through.
 *
 * `@see` references in documentation blocks are tolerated: they do not load.
 */
final class CoreDependsOnNoHostTest extends TestCase
{
    private const CORE = __DIR__ . '/../../../src/Durable';

    /**
     * @return iterable<string, array{string}>
     */
    public static function coreFiles(): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::CORE));

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                yield $file->getPathname() => [$file->getPathname()];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coreFiles')]
    public function testNoCoreFileImportsAHostOrABridge(string $path): void
    {
        $forbidden = [];
        foreach (file($path) ?: [] as $line) {
            if (preg_match('/^use (Gplanchat\\\\Durable\\\\Bundle\\\\|Gplanchat\\\\Bridge\\\\)\S+/', $line, $match)) {
                $forbidden[] = trim($match[0]);
            }
        }

        self::assertSame([], $forbidden, sprintf(
            '%s imports a host package. gplanchat/durable requires neither the Symfony bundle nor any bridge, so this is a fatal error on every host that does not install it — and it looks perfectly healthy under Symfony.',
            basename($path),
        ));
    }
}
