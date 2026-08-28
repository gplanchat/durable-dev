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
 * Une application qui a besoin du cluster **sans** lui confier son journal.
 *
 * Le cas vient d'une boutique qui sert une opération Nexus : servir exige une connexion Temporal,
 * mais son tableau de bord lit un journal DBAL et doit continuer à le lire. Jusqu'ici les deux
 * étaient déclarés exclusifs, et l'exclusion était juste tant que « DSN » voulait dire « le cluster
 * est le journal ». `temporal.journal: false` sépare les deux phrases : il n'y a toujours qu'une
 * source de vérité, et c'est `event_store` qui la nomme.
 *
 * @see openspec/changes/demo-nexus-deux-applications/tasks.md §2.1
 */
final class DurableTemporalWithoutJournalTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=demo-boutique&tls=0';

    public function testADbalJournalAndATemporalDsnAreStillRefusedWhenTemporalClaimsTheJournal(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/exclusifs/');

        $this->load([
            'event_store' => ['type' => 'dbal'],
            'temporal' => ['dsn' => self::DSN],
        ]);
    }

    public function testTheRefusalNamesTheWayOut(): void
    {
        // Le mode d'échec que ce message évite : lire « exclusifs » et conclure qu'une boutique
        // DBAL ne peut pas servir Nexus, alors qu'il lui manque une ligne.
        try {
            $this->load([
                'event_store' => ['type' => 'dbal'],
                'temporal' => ['dsn' => self::DSN],
            ]);
            self::fail('Le conteneur devait refuser.');
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

        // Ce que `NexusHandlerPass` lit pour savoir si ce backend sait router : sans lui, un
        // gestionnaire déclaré est un service qui ne reçoit jamais rien.
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
