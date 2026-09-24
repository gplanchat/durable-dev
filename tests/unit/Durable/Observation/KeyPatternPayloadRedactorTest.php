<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\KeyPatternPayloadRedactor;
use PHPUnit\Framework\TestCase;

/**
 * What a diagnostic surface shows of a payload it copies out of the journal (#335).
 */
final class KeyPatternPayloadRedactorTest extends TestCase
{
    public function testASecretKeyIsMaskedAtAnyDepth(): void
    {
        $redacted = (new KeyPatternPayloadRedactor())->redact([
            'email' => 'ada@example.com',
            'password' => 'hunter2',
            'payment' => ['cardNumber' => '4111111111111111', 'amount' => 42],
            'headers' => [['Authorization' => 'Bearer x'], ['X-Api-Token' => 'y']],
        ]);

        self::assertSame([
            'email' => 'ada@example.com',
            'password' => KeyPatternPayloadRedactor::MASK,
            'payment' => ['cardNumber' => KeyPatternPayloadRedactor::MASK, 'amount' => 42],
            'headers' => [['Authorization' => KeyPatternPayloadRedactor::MASK], ['X-Api-Token' => KeyPatternPayloadRedactor::MASK]],
        ], $redacted);
    }

    public function testAnApiKeyIsMaskedWhateverItsSpelling(): void
    {
        // The Temporal DSN takes `api_key` (#353); a payload carries the same thing as `apiKey`.
        $redacted = (new KeyPatternPayloadRedactor())->redact(['api_key' => 'a', 'apiKey' => 'b', 'X-Api-Key' => 'c', 'keyword' => 'd']);

        self::assertSame([
            'api_key' => KeyPatternPayloadRedactor::MASK,
            'apiKey' => KeyPatternPayloadRedactor::MASK,
            'X-Api-Key' => KeyPatternPayloadRedactor::MASK,
            'keyword' => 'd',
        ], $redacted);
    }

    public function testALongStringIsTruncatedAndSaysByHowMuch(): void
    {
        $redacted = (new KeyPatternPayloadRedactor(maxStringBytes: 8))->redact(['note' => str_repeat('a', 20)]);

        self::assertSame(['note' => 'aaaaaaaa… (12 more bytes)'], $redacted);
    }

    public function testTheCutNeverSplitsACharacter(): void
    {
        // 'é' is two bytes: a cut at 3 bytes would leave half of the second one.
        $redacted = (new KeyPatternPayloadRedactor(maxStringBytes: 3))->redact('ééé');

        self::assertSame('é… (4 more bytes)', $redacted);
    }

    public function testScalarsAndNullPassThrough(): void
    {
        $redactor = new KeyPatternPayloadRedactor();

        self::assertNull($redactor->redact(null));
        self::assertSame(3.0, $redactor->redact(3.0));
        self::assertSame('short', $redactor->redact('short'));
    }
}
