<?php

declare(strict_types=1);

namespace unit\DurableRector;

use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * No backend reads the `cancellationType` option (#1052): a call site that sets it loses the
 * argument, and an argument of another call that has the same name is left alone.
 */
final class ActivityCancellationTypeOptionRectorTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function testTheOptionIsDropped(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public static function provideData(): \Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/CancellationType');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/cancellation-type.php';
    }
}
