<?php

declare(strict_types=1);

namespace unit\DurableRector;

use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class TemporalFacadeToEnvironmentRectorTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function testTheFacadeBecomesAnInjectedEnvironment(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public static function provideData(): \Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/Facade');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/facade.php';
    }
}
