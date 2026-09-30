<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * `durable:health` exists where workers poll Temporal task queues, and checks the roles that do.
 */
final class HealthCommandWiringTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=default';

    public function testOnTheTemporalBackendItChecksTheWorkflowAndActivityRoles(): void
    {
        $container = $this->load(['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]]);

        self::assertSame([['command' => 'durable:health']], $container->getDefinition('durable.command.health')->getTag('console.command'));
        self::assertSame(['workflow', 'activity'], $container->getDefinition('durable.worker_presence')->getArgument(1));
    }

    public function testAJournalKeptInSqlLeavesNoWorkflowRoleOnTheCluster(): void
    {
        $definition = $this->load(['backend' => 'dbal', 'temporal' => ['dsn' => self::DSN]])->getDefinition('durable.worker_presence');

        self::assertSame([], $definition->getArgument(1), 'its workers consume the application\'s transports, not a task queue');
    }

    public function testWithoutAClusterThereIsNoCommand(): void
    {
        self::assertFalse($this->load(['backend' => 'in_memory'])->hasDefinition('durable.command.health'));
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
