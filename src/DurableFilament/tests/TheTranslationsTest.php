<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Tests;

use Illuminate\Support\Arr;
use PHPUnit\Framework\TestCase;

final class TheTranslationsTest extends TestCase
{
    public function testFrenchTranslatesEveryEnglishKey(): void
    {
        $keys = static fn(string $locale): array => array_keys(Arr::dot(require \dirname(__DIR__) . "/lang/$locale/durable.php"));

        self::assertSame($keys('en'), $keys('fr'));
    }
}
