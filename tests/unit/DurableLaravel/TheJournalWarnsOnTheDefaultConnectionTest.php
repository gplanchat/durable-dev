<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * DUR054, decision 6: a journal on the application's default connection is warned about at boot,
 * never refused. The application keeps running exactly as before.
 */
final class TheJournalWarnsOnTheDefaultConnectionTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null}>
     */
    public static function sharedConnections(): iterable
    {
        yield 'unset, so the default' => [null];
        yield 'the default, named' => ['app'];
    }

    #[DataProvider('sharedConnections')]
    public function testAJournalOnTheDefaultConnectionIsWarnedAbout(?string $connection): void
    {
        $logs = $this->boot(['backend' => 'illuminate', 'connection' => $connection]);

        self::assertCount(1, $logs->records);
        [$level, $message] = $logs->records[0];
        self::assertSame('warning', $level);
        self::assertStringContainsString('"app"', $message);
        self::assertStringContainsString('durable.connection', $message);
        self::assertStringContainsString('DUR054', $message);
    }

    public function testAJournalOnAConnectionOfItsOwnIsNot(): void
    {
        self::assertSame([], $this->boot(['backend' => 'illuminate', 'connection' => 'durable'])->records);
    }

    public function testABackendWithoutASqlJournalIsNot(): void
    {
        self::assertSame([], $this->boot(['backend' => 'memory'])->records);
    }

    /**
     * @param array<string, mixed> $durable
     *
     * @return object{records: list<array{string, string}>}
     */
    private function boot(array $durable): object
    {
        $app = new Container();
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app->instance(Connection::class, $capsule->getConnection());
        $app->instance('config', new \ArrayObject([
            'durable' => $durable,
            'database' => ['default' => 'app'],
        ], \ArrayObject::ARRAY_AS_PROPS));

        $logs = new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        };
        $app->instance(LoggerInterface::class, $logs);

        $provider = new DurableServiceProvider($app);
        $provider->register();
        $provider->boot();

        return $logs;
    }
}
