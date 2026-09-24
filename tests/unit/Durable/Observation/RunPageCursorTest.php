<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\RunPageCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cursor travels between two page requests, so its encoding is a wire contract: a cursor a
 * catalogue handed out before this class existed must still decode (#362).
 */
final class RunPageCursorTest extends TestCase
{
    public function testTheEncodingIsTheOneTheCataloguesAlreadyHandOut(): void
    {
        $cursor = new RunPageCursor('2026-09-24 12:00:00', 'exec-1');

        self::assertSame(base64_encode("2026-09-24 12:00:00\0exec-1"), $cursor->encode());
    }

    public function testACursorComesBackAsItWent(): void
    {
        $decoded = RunPageCursor::decode((new RunPageCursor('2026-09-24 12:00:00', 'exec-1'))->encode());

        self::assertEquals(new RunPageCursor('2026-09-24 12:00:00', 'exec-1'), $decoded);
    }

    /**
     * Only the first separator splits: the execution id is whatever follows it.
     */
    public function testAnExecutionIdCarryingTheSeparatorSurvives(): void
    {
        $decoded = RunPageCursor::decode((new RunPageCursor('2026-09-24 12:00:00', "exec\0odd"))->encode());

        self::assertSame("exec\0odd", $decoded?->executionId);
    }

    public function testAnEmptyExecutionIdIsAPosition(): void
    {
        self::assertSame('', RunPageCursor::decode(base64_encode("2026-09-24 12:00:00\0"))?->executionId);
    }

    /**
     * A cursor that does not decode is the first page, not an error: a URL edited by hand, or one
     * from another catalogue, restarts the listing.
     *
     * @return iterable<string, array{string|null}>
     */
    public static function firstPage(): iterable
    {
        yield 'no cursor' => [null];
        yield 'an empty cursor' => [''];
        yield 'not base64' => ['***'];
        yield 'no separator' => [base64_encode('2026-09-24 12:00:00')];
        yield 'no start date' => [base64_encode("\0exec-1")];
        // Compared as a date on some platforms: PostgreSQL refuses the whole query (#534 review).
        yield 'a start that is not a date' => [base64_encode("not-a-date\0exec-1")];
    }

    #[DataProvider('firstPage')]
    public function testWhatDoesNotDecodeIsTheFirstPage(?string $raw): void
    {
        self::assertNull(RunPageCursor::decode($raw));
    }
}
