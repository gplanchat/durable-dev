<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\Command;

use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Bundle\Command\SetupCommand;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * With `dbal.auto_setup: false`, nothing created the tables: the first write died on a raw
 * `TableNotFoundException`. `durable:setup` is Durable's `messenger:setup-transports` (#339).
 */
final class SetupCommandTest extends TestCase
{
    public function testItCreatesTheTablesAutoSetupWouldNot(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tester = new CommandTester(new SetupCommand(new DurableSchema($connection, autoSetup: false)));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertTrue($connection->createSchemaManager()->tablesExist(['durable_events', 'durable_workflow_runs']));
    }

    public function testTheCommandExistsOnlyWithASqlStore(): void
    {
        self::assertFalse($this->load([])->hasDefinition(SetupCommand::class));
        self::assertTrue($this->load(['event_store' => ['type' => 'dbal']])->getDefinition(SetupCommand::class)->hasTag('console.command'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        (new DurableExtension())->load([$config], $container);

        return $container;
    }
}
