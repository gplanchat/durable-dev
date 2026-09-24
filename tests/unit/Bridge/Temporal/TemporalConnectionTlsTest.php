<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemporalConnectionTlsTest extends TestCase
{
    public function testTheDsnCarriesTheCaTheClientCertificateAndTheApiKey(): void
    {
        $file = rawurlencode(__FILE__);
        $connection = TemporalConnection::fromDsn("temporal+tls://ns.acct.tmprl.cloud:7233?namespace=ns.acct&ca={$file}&cert={$file}&key={$file}&api_key=s3cr%2Bt");

        self::assertTrue($connection->tls);
        self::assertSame(__FILE__, $connection->tlsCa);
        self::assertSame(__FILE__, $connection->tlsCert);
        self::assertSame(__FILE__, $connection->tlsKey);
        self::assertSame(
            ['authorization' => ['Bearer s3cr+t'], 'temporal-namespace' => ['ns.acct']],
            $connection->metadata(),
            'Temporal Cloud routes an API key call by the namespace header',
        );
    }

    public function testWithoutAnApiKeyNoMetadataIsAdded(): void
    {
        self::assertSame([], TemporalConnection::fromDsn('temporal+tls://127.0.0.1')->metadata());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        $file = rawurlencode(__FILE__);

        yield 'a CA without TLS' => ["temporal://127.0.0.1?ca={$file}", 'TLS'];
        yield 'an API key without TLS' => ['temporal://127.0.0.1?api_key=k', 'TLS'];
        yield 'a certificate without its key' => ["temporal+tls://127.0.0.1?cert={$file}", 'together'];
        yield 'a key without its certificate' => ["temporal+tls://127.0.0.1?key={$file}", 'together'];
        yield 'a CA nobody can read' => ['temporal+tls://127.0.0.1?ca=/nonexistent/ca.pem', 'ca'];
    }

    #[DataProvider('refused')]
    public function testASettingThatWouldBeIgnoredOrFailLaterIsRefusedAtWiringTime(string $dsn, string $named): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#' . $named . '#');

        TemporalConnection::fromDsn($dsn);
    }

    public function testTheApiKeyStaysOutOfADump(): void
    {
        $connection = TemporalConnection::fromDsn('temporal+tls://127.0.0.1?api_key=s3cret');

        self::assertStringNotContainsString('s3cret', print_r($connection, true));
    }
}
