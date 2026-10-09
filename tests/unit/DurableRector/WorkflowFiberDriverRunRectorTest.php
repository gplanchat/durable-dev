<?php

declare(strict_types=1);

namespace unit\DurableRector;

use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * `WorkflowFiberDriver::run()` reads the execution id from its context (#682): the id that came
 * first is dropped, once, and a call already migrated is left alone.
 */
final class WorkflowFiberDriverRunRectorTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function testTheExecutionIdArgumentIsDropped(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public static function provideData(): \Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/FiberDriver');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/fiber-driver.php';
    }
}
