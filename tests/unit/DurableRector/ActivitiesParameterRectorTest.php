<?php

declare(strict_types=1);

namespace unit\DurableRector;

use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\Fixture\FixtureSplitter;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * A stub the `#[Activities]` attribute can carry becomes a parameter of the workflow method (#778);
 * one it cannot carry keeps its constructor form.
 */
final class ActivitiesParameterRectorTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function testStubsMoveToParametersWhenTheAttributeCarriesThem(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    /** A second run over the rule's own output changes nothing. */
    #[DataProvider('provideData')]
    public function testTheRuleIsIdempotent(string $filePath): void
    {
        $contents = (string) file_get_contents($filePath);
        $output = FixtureSplitter::containsSplit($contents) ? FixtureSplitter::splitFixtureFileContents($contents)[1] : $contents;
        $rerun = sys_get_temp_dir() . '/durable-rector-rerun-' . basename($filePath);
        file_put_contents($rerun, $output);

        try {
            $this->doTestFile($rerun);
        } finally {
            @unlink($rerun);
        }
    }

    public static function provideData(): \Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture/ActivitiesParameter');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/activities-parameter.php';
    }
}
