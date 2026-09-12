<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemporalConnectionTransportTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool, 3: string}>
     */
    public static function schemes(): iterable
    {
        yield 'plain gRPC, auto' => ['temporal://127.0.0.1', TemporalConnection::TRANSPORT_AUTO, false, '127.0.0.1:7233'];
        yield 'gRPC over TLS' => ['temporal+tls://127.0.0.1', TemporalConnection::TRANSPORT_AUTO, true, '127.0.0.1:7233'];
        yield 'JSON gateway' => ['temporal+http://127.0.0.1', TemporalConnection::TRANSPORT_HTTP, false, '127.0.0.1:7243'];
        yield 'JSON gateway over TLS' => ['temporal+https://127.0.0.1', TemporalConnection::TRANSPORT_HTTP, true, '127.0.0.1:7243'];
        yield 'explicit port wins' => ['temporal+https://127.0.0.1:9000', TemporalConnection::TRANSPORT_HTTP, true, '127.0.0.1:9000'];
        yield 'tls= still works' => ['temporal://127.0.0.1?tls=1', TemporalConnection::TRANSPORT_AUTO, true, '127.0.0.1:7233'];
        yield 'transport= overrides the scheme' => ['temporal://127.0.0.1?transport=grpc-curl', TemporalConnection::TRANSPORT_GRPC_CURL, false, '127.0.0.1:7233'];
        yield 'legacy journal scheme' => ['temporal-journal://127.0.0.1', TemporalConnection::TRANSPORT_AUTO, false, '127.0.0.1:7233'];
    }

    #[DataProvider('schemes')]
    public function testTheSchemeNamesTheWireAndTheEncryption(string $dsn, string $transport, bool $tls, string $target): void
    {
        $connection = TemporalConnection::fromDsn($dsn);

        self::assertSame($transport, $connection->transport);
        self::assertSame($tls, $connection->tls);
        self::assertSame($target, $connection->target);
    }

    public function testTheFourSchemesAndTheLegacyOnesAreRecognised(): void
    {
        foreach (['temporal://h', 'temporal+tls://h', 'temporal+http://h', 'temporal+https://h', 'TEMPORAL+HTTPS://h', 'temporal-journal://h', 'temporal-application://h'] as $dsn) {
            self::assertTrue(TemporalConnection::isTemporalDsn($dsn), $dsn);
        }
        foreach (['temporal+curl://h', 'temporals://h', 'doctrine://h', 'http://h'] as $dsn) {
            self::assertFalse(TemporalConnection::isTemporalDsn($dsn), $dsn);
        }
    }

    public function testAnUnknownSchemeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TemporalConnection::fromDsn('temporal+curl://127.0.0.1');
    }

    public function testAnUnknownTransportIsRefusedAtWiringTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TemporalConnection::fromDsn('temporal://127.0.0.1?transport=carrier-pigeon');
    }
}
