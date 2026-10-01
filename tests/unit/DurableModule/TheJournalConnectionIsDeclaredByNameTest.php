<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\DurableModule\Runtime\JournalConnectionResolver;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

require_once __DIR__ . '/Fixture/magento-resource-connection.php';

/**
 * The journal's connection comes from `resource/durable` in `env.php`, resolved by name (#735,
 * DUR056 decision 3). `getConnection('durable')` returns the shop's connection when
 * `resource/durable` is missing, so every case below also asserts it is never called.
 */
final class TheJournalConnectionIsDeclaredByNameTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    public function testADurableConnectionWithoutResourceDurableThrowsNamingTheMissingKey(): void
    {
        $resolver = $this->resolver(['db/connection/durable' => ['host' => 'journal']], $this->connections(null));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('{resource/durable}');

        $resolver->resolve();
    }

    public function testAResourceNamingAnUndeclaredConnectionThrowsNamingThatConnection(): void
    {
        $resolver = $this->resolver(['resource/durable/connection' => 'journal'], $this->connections(null));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('{"journal".*db/connection/journal}');

        $resolver->resolve();
    }

    public function testADedicatedConnectionIsResolvedByNameWithoutAWarning(): void
    {
        $adapter = $this->createStub(AdapterInterface::class);
        $resolver = $this->resolver(
            ['resource/durable/connection' => 'durable', 'db/connection/durable' => ['host' => 'journal']],
            $this->connections('durable', $adapter),
        );

        self::assertSame($adapter, $resolver->resolve());
        self::assertSame([], $this->warnings);
    }

    public function testTheDefaultConnectionIsResolvedWithOneWarningThatNamesIt(): void
    {
        $adapter = $this->createStub(AdapterInterface::class);
        $resolver = $this->resolver(
            ['resource/durable/connection' => 'default', 'db/connection/default' => ['host' => 'shop']],
            $this->connections('default', $adapter),
        );

        self::assertSame($adapter, $resolver->resolve());
        self::assertSame($adapter, $resolver->resolve());
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('"default"', $this->warnings[0]);
        self::assertStringContainsString('db/connection/durable', $this->warnings[0]);
    }

    public function testResourceDurableAndATemporalDsnTogetherThrowNamingBothKeys(): void
    {
        $resolver = $this->resolver(
            [
                'resource/durable/connection' => 'durable',
                'db/connection/durable' => ['host' => 'journal'],
                'durable/temporal/dsn' => 'temporal://localhost:7233',
            ],
            $this->connections(null),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('{resource/durable.*durable/temporal/dsn|durable/temporal/dsn.*resource/durable}');

        $resolver->resolve();
    }

    /**
     * @param array<string, mixed> $env `env.php` flattened by path, as `DeploymentConfig::get()` reads it
     */
    private function resolver(array $env, ResourceConnection $connections): JournalConnectionResolver
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(static fn($key = null, $default = null): mixed => $env[$key] ?? $default);

        $warnings = &$this->warnings;
        $logger = new class ($warnings) extends AbstractLogger {
            /** @param list<string> $warnings */
            public function __construct(private array &$warnings) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ('warning' === $level) {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        return new JournalConnectionResolver($connections, $config, $logger);
    }

    /** `$name` is the only connection resolved by name; null means none may be. */
    private function connections(?string $name, ?AdapterInterface $adapter = null): ResourceConnection
    {
        $connections = $this->createMock(ResourceConnection::class);
        $connections->expects(self::never())->method('getConnection');
        $connections->expects(null === $name ? self::never() : self::atLeastOnce())
            ->method('getConnectionByName')
            ->with($name ?? '')
            ->willReturn($adapter ?? $this->createStub(AdapterInterface::class));

        return $connections;
    }
}
