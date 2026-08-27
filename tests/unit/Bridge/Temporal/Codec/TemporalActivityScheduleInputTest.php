<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Codec;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput;
use Gplanchat\Durable\Event\ActivityScheduled;
use PHPUnit\Framework\TestCase;

final class TemporalActivityScheduleInputTest extends TestCase
{
    public function testEncodeFromScheduledCarriesTheActivityIdentity(): void
    {
        $scheduled = new ActivityScheduled('e1', 'a1', 'T', ['x' => 2], ['k' => 'v']);

        $row = TemporalActivityScheduleInput::encodeFromScheduled($scheduled);

        self::assertSame('e1', $row['executionId']);
        self::assertSame('a1', $row['activityId']);
        self::assertSame('T', $row['activityName']);
        self::assertSame(['x' => 2], $row['payload']);
    }

    public function testEncodedRowSurvivesTheRoundTripThroughPayloads(): void
    {
        $scheduled = new ActivityScheduled('e1', 'a1', 'T', ['x' => 2], ['k' => 'v']);
        $row = TemporalActivityScheduleInput::encodeFromScheduled($scheduled);

        $payloads = JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($row));

        self::assertNotEmpty($payloads);
        self::assertSame([$row], JsonPlainPayload::decodePayloads($payloads));
    }
}
