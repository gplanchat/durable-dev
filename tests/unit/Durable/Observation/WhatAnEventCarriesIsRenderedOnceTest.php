<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\RecordedDetails;
use PHPUnit\Framework\TestCase;

/**
 * What a backend recorded with an event, formatted **once**.
 *
 * The content is the backend's vocabulary and is not normalized: a homegrown journal can therefore
 * hold a payload that does not survive rendering. Magento knew it — its block tolerated partial
 * output and fell back on a plain row — and Sylius did not: its template passed the payload to
 * `json_encode` without tolerance, got `false`, and rendered an **empty unfoldable**. Which is
 * precisely the screen an operator opens as a last resort, and that opens onto nothing.
 */
final class WhatAnEventCarriesIsRenderedOnceTest extends TestCase
{
    public function testNothingRecordedHasNothingToUnfold(): void
    {
        self::assertNull(RecordedDetails::of([]));
    }

    public function testWhatWasRecordedComesBackReadable(): void
    {
        $rendered = RecordedDetails::of(['payload' => ['customerId' => 'cus-42']]);

        self::assertIsString($rendered);
        self::assertStringContainsString('cus-42', $rendered);
        self::assertStringContainsString("\n", $rendered, 'an operator reads a payload, they do not decipher it');
    }

    public function testABadlyEncodedValueDoesNotTakeTheWholeLineDownWithIt(): void
    {
        // The reachable case: a byte string that is not valid UTF-8. Without tolerance,
        // `json_encode` returns `false` — and the rest of the payload, perfectly readable,
        // disappeared along with the offending byte.
        $rendered = RecordedDetails::of(['blob' => "\xB1\x31", 'orderId' => 'ORD-7']);

        self::assertIsString($rendered);
        self::assertStringContainsString('ORD-7', $rendered);
    }

    public function testAValueOfATypeJsonCannotHoldIsShownAsAbsentAndNotAsAnError(): void
    {
        // A resource, a closed object: partial output renders them as `null`, and the rest of the
        // payload arrives whole. That is better than the plain row the contract allows — the
        // operator sees what was recorded **and** that one field could not be.
        $handle = fopen('php://memory', 'r');
        self::assertIsResource($handle);

        $rendered = RecordedDetails::of(['handle' => $handle, 'orderId' => 'ORD-7']);
        fclose($handle);

        self::assertIsString($rendered);
        self::assertStringContainsString('ORD-7', $rendered);
        self::assertStringContainsString('null', $rendered);
    }

}
