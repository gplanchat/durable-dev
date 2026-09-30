<?php

declare(strict_types=1);

namespace unit\DurableRector;

use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * The journal events are built from an `ExecutionId`, not a string (#682): a construction or a
 * factory call that passes a string gets it wrapped, one that already passes the value object is
 * left alone.
 */
final class ExecutionIdEventArgumentRectorTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function testStringArgumentsAreWrapped(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public static function provideData(): \Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/ExecutionIdEvent');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/execution-id-event.php';
    }
}
