<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\DurableModule\Runtime\BackendSelectionException;
use Gplanchat\DurableModule\Runtime\JournalConnectionResolver;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/Fixture/magento-resource-connection.php';
require_once __DIR__ . '/Fixture/magento-ddl-table.php';

/** `durable:worker` asks the factory which backend it drains; two declared backends fail there, not later (#754, #736). */
final class TheWorkerFollowsTheOneBackendTest extends TestCase
{
    public function testADatabaseAndATemporalDsnTogetherFailBeforeAnyQueueIsDrained(): void
    {
        $this->expectException(BackendSelectionException::class);

        $this->factory(['resource/durable/connection' => 'durable', 'db/connection/durable' => ['host' => 'j'], 'durable/temporal/dsn' => 'temporal://127.0.0.1:7233'])->usesDatabase();
    }

    public function testADatabaseAloneIsDrained(): void
    {
        self::assertTrue($this->factory(['resource/durable/connection' => 'durable', 'db/connection/durable' => ['host' => 'j']])->usesDatabase());
    }

    /** @param array<string, mixed> $env */
    private function factory(array $env): RuntimeFactory
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(static fn($key = null, $default = null): mixed => $env[$key] ?? $default);
        $connections = $this->createStub(ResourceConnection::class);
        $connections->method('getConnectionByName')->willReturn($this->createStub(AdapterInterface::class));

        return new RuntimeFactory(deploymentConfig: $config, journalConnection: new JournalConnectionResolver($connections, $config, new NullLogger()));
    }
}
