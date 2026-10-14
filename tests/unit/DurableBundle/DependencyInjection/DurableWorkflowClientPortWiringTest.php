<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Bridge\Temporal\Port\TemporalWorkflowClientPort;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Store\InMemoryWorkflowClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The client port is bound on every backend the bundle runs. Illuminate is not one of them: it is
 * the Laravel host's backend.
 */
final class DurableWorkflowClientPortWiringTest extends TestCase
{
    public function testTheInMemoryBackendBindsTheJournalClient(): void
    {
        $definition = $this->load([])->findDefinition(WorkflowClientPort::class);

        self::assertSame(InMemoryWorkflowClient::class, $definition->getClass());
        self::assertSame('in-memory', $definition->getArgument(7));
    }

    public function testTheDbalBackendBindsTheJournalClientAndNamesItself(): void
    {
        $definition = $this->load([
            'event_store' => ['type' => 'dbal'],
            'workflow_metadata' => ['type' => 'dbal'],
        ])->findDefinition(WorkflowClientPort::class);

        self::assertSame(InMemoryWorkflowClient::class, $definition->getClass());
        self::assertSame('dbal', $definition->getArgument(7), 'a refused start option names the backend that refuses it');
    }

    public function testTheTemporalBackendBindsTheTemporalClient(): void
    {
        $container = $this->load(['temporal' => ['dsn' => 'temporal://localhost:7233']]);

        self::assertSame(TemporalWorkflowClientPort::class, $container->findDefinition(WorkflowClientPort::class)->getClass());
    }

    public function testTemporalWithoutItsJournalKeepsTheJournalClient(): void
    {
        $container = $this->load(['temporal' => ['dsn' => 'temporal://localhost:7233', 'journal' => false]]);

        self::assertSame(
            InMemoryWorkflowClient::class,
            $container->findDefinition(WorkflowClientPort::class)->getClass(),
            'the resume dispatcher is the Messenger one in this mode, and the port follows it',
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new DurableExtension())->load([$config], $container);

        return $container;
    }
}
