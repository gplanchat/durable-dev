<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * An application that needs the cluster **without** handing it the journal.
 *
 * The case comes from a store that serves a Nexus operation: serving requires a Temporal
 * connection, but its dashboard reads a DBAL journal and has to keep reading it. Until now the two
 * were declared mutually exclusive, and the exclusion was right as long as "DSN" meant "the
 * cluster is the journal". `temporal.journal: false` separates the two statements: there is still
 * only one source of truth, and it is `event_store` that names it.
 *
 * @see openspec/changes/demo-nexus-deux-applications/tasks.md §2.1
 */
final class DurableTemporalWithoutJournalTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=demo-shop&tls=0';

    public function testADbalJournalAndATemporalDsnAreStillRefusedWhenTemporalClaimsTheJournal(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/mutually exclusive/');

        $this->load([
            'event_store' => ['type' => 'dbal'],
            'temporal' => ['dsn' => self::DSN],
        ]);
    }

    public function testTheRefusalNamesTheWayOut(): void
    {
        // The failure mode this message avoids: reading "mutually exclusive" and concluding that a
        // DBAL store cannot serve Nexus, when all it is missing is one line.
        try {
            $this->load([
                'event_store' => ['type' => 'dbal'],
                'temporal' => ['dsn' => self::DSN],
            ]);
            self::fail('The container was supposed to refuse.');
        } catch (\LogicException $refus) {
            self::assertStringContainsString('temporal.journal: false', $refus->getMessage());
        }
    }

    public function testWithoutTheJournalTheDashboardKeepsReadingDbal(): void
    {
        $container = $this->load([
            'event_store' => ['type' => 'dbal'],
            'workflow_metadata' => ['type' => 'dbal'],
            'temporal' => ['dsn' => self::DSN, 'journal' => false],
        ]);

        self::assertSame(
            DbalWorkflowRunCatalog::class,
            $container->findDefinition(WorkflowRunCatalogInterface::class)->getClass(),
        );
        self::assertStringStartsWith(
            'durable.event_store.dbal',
            (string) $container->getAlias(EventStoreInterface::class),
        );
    }

    public function testWithoutTheJournalTheClusterIsStillReachableAndNexusStillRoutes(): void
    {
        $container = $this->load([
            'event_store' => ['type' => 'dbal'],
            'workflow_metadata' => ['type' => 'dbal'],
            'temporal' => ['dsn' => self::DSN, 'journal' => false],
        ]);

        // What `NexusHandlerPass` reads to know whether this backend can route: without it, a
        // declared handler is a service that never receives anything.
        self::assertTrue($container->hasDefinition('durable.temporal.nexus_registry'));
        self::assertTrue($container->hasDefinition('durable.temporal.nexus_worker'));
        self::assertTrue($container->hasDefinition('durable.temporal.connection'));
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
