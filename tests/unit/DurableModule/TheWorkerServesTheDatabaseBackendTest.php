<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\DurableModule\Console\Command\RunWorkerCommand;
use Gplanchat\DurableModule\Runtime\JournalConnectionResolver;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/Fixture/magento-resource-connection.php';
require_once __DIR__ . '/Fixture/magento-ddl-table.php';

/**
 * `durable:worker` on the SQL backend (#736): it drains the table queues, has no Nexus role, and
 * says so when it is asked for one. The loop and its failure handling run against MySQL in the
 * Magento bench (`TheDatabaseWorkerTest`).
 */
final class TheWorkerServesTheDatabaseBackendTest extends TestCase
{
    public function testTheNexusRoleIsRefusedWithAnAnswerAndAWayOut(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The database backend has no Nexus role: Nexus operations need a Temporal cluster. Run durable:worker with --role=journal or --role=activity, or without --role to serve both.');

        (new CommandTester(new RunWorkerCommand($this->factory())))->execute(['--role' => 'nexus']);
    }

    public function testAnUnknownRoleNamesTheTwoThisBackendHas(): void
    {
        $this->expectExceptionMessage('journal or activity');

        (new CommandTester(new RunWorkerCommand($this->factory())))->execute(['--role' => 'workflow']);
    }

    private function factory(): RuntimeFactory
    {
        $env = ['resource/durable/connection' => 'durable', 'db/connection/durable' => ['host' => 'journal']];
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(static fn($key = null, $default = null): mixed => $env[$key] ?? $default);
        $connections = $this->createStub(ResourceConnection::class);
        $connections->method('getConnectionByName')->willReturn($this->createStub(AdapterInterface::class));

        return new RuntimeFactory(
            deploymentConfig: $config,
            journalConnection: new JournalConnectionResolver($connections, $config, new NullLogger()),
        );
    }
}
