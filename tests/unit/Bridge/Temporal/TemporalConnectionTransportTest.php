<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use PHPUnit\Framework\TestCase;

final class TemporalConnectionTransportTest extends TestCase
{
    public function testTheDsnNamesTheTransportAndGrpcStaysTheDefault(): void
    {
        self::assertSame(TemporalConnection::TRANSPORT_GRPC, TemporalConnection::fromDsn('temporal://127.0.0.1')->transport);
        self::assertSame(TemporalConnection::TRANSPORT_GRPC_CURL, TemporalConnection::fromDsn('temporal://127.0.0.1?transport=grpc-curl')->transport);
        self::assertSame(TemporalConnection::TRANSPORT_HTTP, TemporalConnection::fromDsn('temporal://127.0.0.1?transport=http')->transport);
    }

    public function testTheJsonGatewayDefaultsToItsOwnPortAndGrpcToTheFrontendPort(): void
    {
        self::assertSame('127.0.0.1:7243', TemporalConnection::fromDsn('temporal://127.0.0.1?transport=http')->target);
        self::assertSame('127.0.0.1:7233', TemporalConnection::fromDsn('temporal://127.0.0.1?transport=grpc-curl')->target);
        self::assertSame('127.0.0.1:9000', TemporalConnection::fromDsn('temporal://127.0.0.1:9000?transport=http')->target);
    }

    public function testAnUnknownTransportIsRefusedAtWiringTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TemporalConnection::fromDsn('temporal://127.0.0.1?transport=carrier-pigeon');
    }
}
