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
        yield 'transport=guzzle' => ['temporal://127.0.0.1?transport=guzzle', TemporalConnection::TRANSPORT_GUZZLE, false, '127.0.0.1:7233'];
    }

    #[DataProvider('schemes')]
    public function testTheSchemeNamesTheWireAndTheEncryption(string $dsn, string $transport, bool $tls, string $target): void
    {
        $connection = TemporalConnection::fromDsn($dsn);

        self::assertSame($transport, $connection->transport);
        self::assertSame($tls, $connection->tls);
        self::assertSame($target, $connection->target);
    }

    public function testTheFourSchemesAreRecognised(): void
    {
        foreach (['temporal://h', 'temporal+tls://h', 'temporal+http://h', 'temporal+https://h', 'TEMPORAL+HTTPS://h'] as $dsn) {
            self::assertTrue(TemporalConnection::isTemporalDsn($dsn), $dsn);
        }
        // The legacy schemes named a worker kind, which the DSN no longer carries (#420, #424).
        foreach (['temporal-journal://h', 'temporal-application://h', 'temporal+curl://h', 'temporals://h', 'doctrine://h', 'http://h'] as $dsn) {
            self::assertFalse(TemporalConnection::isTemporalDsn($dsn), $dsn);
        }
    }

    public function testAnUnknownSchemeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TemporalConnection::fromDsn('temporal+curl://127.0.0.1');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function legacySchemes(): iterable
    {
        yield 'journal' => ['temporal-journal://127.0.0.1:7233'];
        yield 'application' => ['temporal-application://127.0.0.1:7233'];
    }

    #[DataProvider('legacySchemes')]
    public function testALegacySchemeIsRefusedAndTheReplacementIsNamed(string $dsn): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#temporal://#');
        $this->expectExceptionMessageMatches('#no longer#');

        TemporalConnection::fromDsn($dsn);
    }

    public function testInnerNoLongerLandsOnTheConnection(): void
    {
        // It fed the Messenger application transport, removed in #422.
        self::assertFalse(property_exists(TemporalConnection::class, 'innerMessengerDsn'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unknownKeys(): iterable
    {
        yield 'a typo' => ['temporal://127.0.0.1?namesapce=orders', 'namesapce'];
        yield 'a key from another client' => ['temporal://127.0.0.1?namespace=orders&ssl=1', 'ssl'];
        yield 'a removed key' => ['temporal://127.0.0.1?inner=doctrine://default', 'inner'];
    }

    #[DataProvider('unknownKeys')]
    public function testAnUnknownQueryKeyIsRefusedByName(string $dsn, string $key): void
    {
        // Ignored, a typo would silently run against the default namespace (R-10).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#"' . $key . '"#');

        TemporalConnection::fromDsn($dsn);
    }

    public function testAnUnknownTransportIsRefusedAtWiringTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TemporalConnection::fromDsn('temporal://127.0.0.1?transport=carrier-pigeon');
    }
}
