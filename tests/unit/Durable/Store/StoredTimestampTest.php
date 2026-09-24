<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Store\StoredTimestamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How a timestamp read back from a SQL column becomes a date (#362): the DBAL and Illuminate
 * stores write UTC, and a column type without a zone gives it back as a bare string.
 */
final class StoredTimestampTest extends TestCase
{
    public function testABareStringIsReadAsUtc(): void
    {
        $date = StoredTimestamp::toDateTime('2026-09-24 12:00:00');

        self::assertSame('2026-09-24T12:00:00+00:00', $date?->format(\DATE_ATOM));
    }

    public function testAnExplicitOffsetWins(): void
    {
        $date = StoredTimestamp::toDateTime('2026-09-24 14:00:00+02:00');

        self::assertNotNull($date);
        self::assertSame('+02:00', $date->format('P'));
        self::assertSame((new \DateTimeImmutable('2026-09-24 12:00:00 UTC'))->getTimestamp(), $date->getTimestamp());
    }

    public function testMicrosecondsAreKept(): void
    {
        self::assertSame('123456', StoredTimestamp::toDateTime('2026-09-24 12:00:00.123456')?->format('u'));
    }

    public function testADateTheDriverAlreadyBuiltIsKeptAsIs(): void
    {
        $date = new \DateTimeImmutable('2026-09-24 12:00:00', new \DateTimeZone('Europe/Paris'));

        self::assertSame($date, StoredTimestamp::toDateTime($date));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function noDate(): iterable
    {
        yield 'null' => [null];
        yield 'an empty string' => [''];
        yield 'an integer' => [1_758_715_200];
        yield 'a mutable date' => [new \DateTime('2026-09-24 12:00:00')];
    }

    #[DataProvider('noDate')]
    public function testAnythingElseIsNoDate(mixed $raw): void
    {
        self::assertNull(StoredTimestamp::toDateTime($raw));
    }
}
