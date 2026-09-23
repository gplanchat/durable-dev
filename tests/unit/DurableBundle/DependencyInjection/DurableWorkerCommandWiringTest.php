<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\Command\DurableWorkerCommand;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The bundle registers `durable:worker` and hands it the activity transport it wired itself (#338).
 */
final class DurableWorkerCommandWiringTest extends TestCase
{
    public function testTheCommandIsRegisteredUnderItsName(): void
    {
        $definition = $this->load([])->getDefinition(DurableWorkerCommand::class);

        self::assertSame([['command' => 'durable:worker']], $definition->getTag('console.command'));
    }

    public function testAMessengerActivityTransportIsHandedToTheCommand(): void
    {
        $definition = $this->load(['activity_transport' => ['type' => 'messenger', 'transport_name' => 'jobs']])->getDefinition(DurableWorkerCommand::class);

        self::assertSame('jobs', $definition->getArgument(2));
    }

    public function testInMemoryActivitiesNeedNoActivityTransport(): void
    {
        $definition = $this->load([])->getDefinition(DurableWorkerCommand::class);

        self::assertNull($definition->getArgument(2));
    }

    public function testTheTemporalBackendIsFlaggedAndNeedsNoActivityTransport(): void
    {
        $definition = $this->load(['temporal' => ['dsn' => 'temporal://127.0.0.1:7233'], 'activity_transport' => ['type' => 'messenger']])->getDefinition(DurableWorkerCommand::class);

        self::assertNull($definition->getArgument(2));
        self::assertTrue($definition->getArgument(3));
    }

    public function testATemporalClusterWithoutItsJournalKeepsTheRoutedResumes(): void
    {
        $definition = $this->load(['temporal' => ['dsn' => 'temporal://127.0.0.1:7233', 'journal' => false]])->getDefinition(DurableWorkerCommand::class);

        self::assertFalse($definition->getArgument(3));
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
