<?php

declare(strict_types=1);

namespace unit\DurableRector;

use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class ActivityContractAttributesRectorTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function testActivityContractIsRewritten(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public static function provideData(): \Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/Activity');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/activity.php';
    }
}
