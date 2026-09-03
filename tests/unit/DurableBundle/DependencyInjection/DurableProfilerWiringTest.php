<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Ce que le profileur coûte quand personne ne le regarde.
 *
 * L'observateur est injecté dans `ExecutionRuntime`, `ExecutionEngine` et
 * `ActivityMessageProcessor` : il est sur le chemin chaud de l'exécution, pas sur celui de la page
 * de debug. Et sa trace n'est vidée que par un écouteur `kernel.request` — or `messenger:consume`
 * n'a pas de requête, donc dans un worker elle grossit tant que le processus vit.
 *
 * Les deux moitiés se traitent ensemble : le tag `kernel.reset` borne le worker de debug, et
 * l'absence pure et simple du profileur borne la production.
 */
final class DurableProfilerWiringTest extends TestCase
{
    public function testLaTraceEstReinitialisableEntreDeuxMessagesDUnWorker(): void
    {
        $definition = $this->load(debug: true)->getDefinition('durable.execution_trace');

        self::assertArrayHasKey(
            'kernel.reset',
            $definition->getTags(),
            "sans ce tag, services_resetter ignore la trace et un worker l'accumule sans borne",
        );
        self::assertSame(
            'reset',
            $definition->getTag('kernel.reset')[0]['method'] ?? null,
            'et le resetter a besoin du nom de la méthode',
        );
    }

    public function testHorsDebugAucunCollecteurNEstEnregistre(): void
    {
        $container = $this->load(debug: false);

        self::assertFalse(
            $container->has('durable.execution_trace'),
            'la trace de profil n\'a rien à faire en production',
        );

        foreach ($container->getDefinitions() as $id => $definition) {
            self::assertArrayNotHasKey(
                'data_collector',
                $definition->getTags(),
                \sprintf('%s ne doit pas collecter hors debug', $id),
            );
        }
    }

    /**
     * Le contrat d'observation reste satisfait : les trois services du chemin chaud le reçoivent
     * en injection, et un conteneur qui ne le fournirait pas ne compilerait plus.
     */
    public function testHorsDebugLObservateurEstUnObjetNul(): void
    {
        $container = $this->load(debug: false);

        self::assertTrue($container->hasAlias(WorkflowExecutionObserverInterface::class));

        $target = (string) $container->getAlias(WorkflowExecutionObserverInterface::class);
        self::assertSame(
            \Gplanchat\Durable\Debug\NullWorkflowExecutionObserver::class,
            $container->getDefinition($target)->getClass(),
        );
    }

    public function testEnDebugLObservateurEstBienLaTrace(): void
    {
        $container = $this->load(debug: true);

        self::assertSame(
            'durable.execution_trace',
            (string) $container->getAlias(WorkflowExecutionObserverInterface::class),
        );
    }

    private function load(bool $debug): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        (new DurableExtension())->load([[]], $container);

        return $container;
    }
}
