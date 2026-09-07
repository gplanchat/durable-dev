<?php

declare(strict_types=1);

namespace unit\DurableRector;

use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * The set that carries a project from one version of Durable to the next.
 *
 * `temporal-sdk.php` brings a project into Durable; this one moves it forward inside. Both exist
 * for the same reason — a name that moves with no procedure is a break discovered in production —
 * and the repository rule is explicit: Rector first, a script otherwise, and documentation in
 * every case.
 */
final class DurableUpgradeSetTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function testMovedClassesAreRewritten(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public static function provideData(): \Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/DurableUpgrade');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/durable-upgrade.php';
    }
}
