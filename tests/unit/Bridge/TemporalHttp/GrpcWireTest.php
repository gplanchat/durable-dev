<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\TemporalHttp;

use Gplanchat\Bridge\TemporalHttp\GrpcWire;
use PHPUnit\Framework\TestCase;

final class GrpcWireTest extends TestCase
{
    public function testAFrameIsAFlagByteALengthAndThePayload(): void
    {
        self::assertSame("\x00\x00\x00\x00\x03abc", GrpcWire::frame('abc'));
        self::assertSame('abc', GrpcWire::unframe("\x00\x00\x00\x00\x03abc"));
    }

    public function testConsecutiveFramesAreConcatenated(): void
    {
        self::assertSame('abcde', GrpcWire::unframe(GrpcWire::frame('abc') . GrpcWire::frame('de')));
        self::assertSame('', GrpcWire::unframe(''));
    }

    public function testATruncatedFrameIsRefusedWithUnknown(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(GrpcWire::UNKNOWN);
        GrpcWire::unframe("\x00\x00\x00\x00\x05ab");
    }

    public function testACompressedFrameIsRefusedWithUnimplemented(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(GrpcWire::UNIMPLEMENTED);
        GrpcWire::unframe("\x01\x00\x00\x00\x01a");
    }

    public function testTheStatusComesFromTheTrailersPercentDecoded(): void
    {
        self::assertSame([0, ''], GrpcWire::status(['grpc-status' => '0'], 200));
        self::assertSame([5, 'workflow not found'], GrpcWire::status(['grpc-status' => '5', 'grpc-message' => 'workflow%20not%20found'], 200));
    }

    public function testAResponseWithoutGrpcStatusIsUnknownAndNamesTheHttpStatus(): void
    {
        [$code, $message] = GrpcWire::status(['content-type' => 'text/html'], 404);
        self::assertSame(GrpcWire::UNKNOWN, $code);
        self::assertStringContainsString('404', $message);
    }

    public function testTheFailureCarriesTheGrpcCodeAsExceptionCode(): void
    {
        $failure = GrpcWire::failure(5, 'gone');
        self::assertSame(5, $failure->getCode());
        self::assertSame('Temporal gRPC error [5]: gone', $failure->getMessage());
    }

    public function testTheTimeoutOptionIsMicrosecondsRoundedUpToMilliseconds(): void
    {
        self::assertSame(0, GrpcWire::timeoutMs([]));
        self::assertSame(60_000, GrpcWire::timeoutMs(['timeout' => 60_000_000]));
        self::assertSame(2, GrpcWire::timeoutMs(['timeout' => 1_001]));
    }

    public function testMetadataBecomesOneHeaderLinePerValue(): void
    {
        self::assertSame(
            ['x-one: a', 'x-many: b', 'x-many: c'],
            GrpcWire::metadataHeaders(['x-one' => 'a', 'x-many' => ['b', 'c'], 'x-skip' => [new \stdClass()]]),
        );
    }
}
