<?php

declare(strict_types=1);

namespace unit\DurableLaravel;

use Gplanchat\Durable\Laravel\Queue\ActivityAttemptLock;
use Gplanchat\Durable\Laravel\Queue\ResumeLock;
use PHPUnit\Framework\TestCase;

/**
 * The bridge of the backend you select is the one you install (#845). `durable-laravel` names the
 * SQL bridge and the Temporal bridge in `suggest`, and holds what every backend shares: the two
 * cache locks, which take no SQL at all.
 */
final class TheBackendBridgeIsSuggestedTest extends TestCase
{
    public function testNeitherBridgeIsRequired(): void
    {
        $composer = $this->composer();

        self::assertArrayNotHasKey('gplanchat/durable-bridge-illuminate', $composer['require']);
        self::assertArrayNotHasKey('gplanchat/durable-bridge-temporal', $composer['require']);
    }

    public function testBothBridgesAreSuggested(): void
    {
        $suggest = $this->composer()['suggest'];

        self::assertArrayHasKey('gplanchat/durable-bridge-illuminate', $suggest);
        self::assertArrayHasKey('gplanchat/durable-bridge-temporal', $suggest);
    }

    public function testTheFilamentDashboardIsSuggestedAndNeverRequired(): void
    {
        $composer = $this->composer();

        self::assertArrayHasKey('gplanchat/durable-filament', $composer['suggest']);
        self::assertArrayNotHasKey('gplanchat/durable-filament', $composer['require']);
    }

    public function testTheLocksLiveInThePackageThatUsesThemOnEveryBackend(): void
    {
        self::assertTrue(class_exists(ResumeLock::class));
        self::assertTrue(class_exists(ActivityAttemptLock::class));
    }

    public function testOnlyTheProviderNamesTheIlluminateBridge(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../src/DurableLaravel', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('php' !== $file->getExtension() || 'DurableServiceProvider.php' === $file->getFilename()) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), 'Gplanchat\\Bridge\\Illuminate')) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame([], $offenders, 'Only the illuminate backend may need the bridge.');
    }

    /** @return array{require: array<string, string>, suggest: array<string, string>} */
    private function composer(): array
    {
        /** @var array{require: array<string, string>, suggest: array<string, string>} */
        return json_decode((string) file_get_contents(__DIR__ . '/../../../src/DurableLaravel/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
    }
}
