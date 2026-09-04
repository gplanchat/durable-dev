<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Ce que le bundle expose, et ce qu'il garde pour lui.
 *
 * Un service public est un point d'entrée que le compilateur ne peut plus retirer ni intégrer, et
 * une promesse de compatibilité implicite : quelqu'un finira par l'appeler. Les implémentations
 * concrètes derrière un alias — le journal DBAL, le catalogue Temporal — et les décorateurs de
 * projection n'ont aucune raison d'être tirés du conteneur : on les atteint par leur interface,
 * qui reste publique et autowirable.
 */
final class DurableContainerSurfaceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function internesQuiNOntPasAEtrePublics(): iterable
    {
        $dbal = ['event_store' => ['type' => 'dbal'], 'workflow_metadata' => ['type' => 'dbal']];

        yield 'journal DBAL' => [$dbal, 'durable.event_store.dbal'];
        yield 'journal DBAL projeté' => [$dbal, 'durable.event_store.dbal.projecting'];
        yield 'métadonnées DBAL, service interne' => [$dbal, 'durable.workflow_metadata_store.inner'];
        yield 'métadonnées DBAL projetées' => [$dbal, 'durable.workflow_metadata_store.projecting'];
        yield 'catalogue DBAL' => [$dbal, 'durable.run_catalog.dbal'];
        yield 'journal mémoire projeté' => [[], 'durable.event_store.in_memory.projecting'];
        yield 'métadonnées mémoire projetées' => [[], 'durable.workflow_metadata_store.in_memory.projecting'];
        yield 'catalogue mémoire' => [[], 'durable.run_catalog.in_memory'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('internesQuiNOntPasAEtrePublics')]
    public function testUnInterneNEstPasPublic(array $config, string $serviceId): void
    {
        $container = $this->load($config);

        self::assertTrue($container->hasDefinition($serviceId), $serviceId . ' doit exister pour ce backend');
        self::assertFalse(
            $container->getDefinition($serviceId)->isPublic(),
            $serviceId . " n'a pas de raison d'être tiré du conteneur : on l'atteint par son interface",
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function surfacePubliqueQuiDoitLeRester(): iterable
    {
        yield 'le journal' => [EventStoreInterface::class];
        yield 'les métadonnées' => [WorkflowMetadataStore::class];
        yield 'le catalogue de runs' => [WorkflowRunCatalogInterface::class];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfacePubliqueQuiDoitLeRester')]
    public function testLaSurfacePubliqueResteJoignable(string $alias): void
    {
        $container = $this->load(['event_store' => ['type' => 'dbal'], 'workflow_metadata' => ['type' => 'dbal']]);

        self::assertTrue($container->hasAlias($alias), $alias . ' doit rester un alias du conteneur');
        self::assertTrue(
            $container->getAlias($alias)->isPublic(),
            $alias . ' est ce que le trait de test livré résout, et ce que la documentation nomme',
        );
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
