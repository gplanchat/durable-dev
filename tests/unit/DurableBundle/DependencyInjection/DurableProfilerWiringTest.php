<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CheckExceptionOnInvalidReferenceBehaviorPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;

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
    private const DSN = 'temporal://127.0.0.1:7233?namespace=demo-boutique&tls=0';

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

    /**
     * Retirer le profileur de la production ne suffit pas : il faut que plus rien ne le réclame.
     *
     * `TemporalWorkflowResumeDispatcher` recevait `durable.execution_trace` par une référence nue.
     * Le service n'étant plus enregistré hors debug, le conteneur d'une application de production
     * configurée en Temporal natif ne compilait plus — et aucun test ne le voyait, tous chargeant
     * une configuration vide, donc sans jamais construire cette branche.
     */
    public function testHorsDebugUneApplicationTemporaleCompileEncore(): void
    {
        $container = $this->load(debug: false, config: ['temporal' => ['dsn' => self::DSN]]);

        $arguments = $container->getDefinition(WorkflowResumeDispatcher::class)->getArguments();
        $trace = $arguments[3] ?? null;

        self::assertInstanceOf(Reference::class, $trace);
        self::assertSame('durable.execution_trace', (string) $trace);
        self::assertSame(
            ContainerInterface::NULL_ON_INVALID_REFERENCE,
            $trace->getInvalidBehavior(),
            'une référence nue vers un service absent hors debug fait échouer la compilation',
        );

        self::assertNotContains(
            'durable.execution_trace',
            self::servicesManquants($container),
            'le conteneur de production ne doit plus réclamer un service que le debug seul enregistre',
        );
    }

    public function testEnDebugLeMemeConteneurRecoitLaVraieTrace(): void
    {
        $container = $this->load(debug: true, config: ['temporal' => ['dsn' => self::DSN]]);

        self::assertTrue($container->has('durable.execution_trace'));
        self::assertSame(
            'durable.execution_trace',
            (string) $container->getDefinition(WorkflowResumeDispatcher::class)->getArgument(3),
        );
    }

    /**
     * `UPGRADE.md` prescrit cette échappatoire aux applications qui veulent observer en
     * production : implémenter le contrat, aliaser l'interface. Elle ne fonctionnait pas — le
     * `setAlias()` de l'extension écrasait celui de l'application, dont les définitions sont
     * pourtant déjà là quand l'extension se charge.
     */
    #[DataProvider('environnements')]
    public function testUnAliasDeLApplicationNEstPasEcrase(bool $debug): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->register('app.observateur', \stdClass::class);
        $container->setAlias(WorkflowExecutionObserverInterface::class, 'app.observateur');

        (new DurableExtension())->load([[]], $container);

        self::assertSame(
            'app.observateur',
            (string) $container->getAlias(WorkflowExecutionObserverInterface::class),
        );
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function environnements(): iterable
    {
        yield 'debug' => [true];
        yield 'production' => [false];
    }

    /**
     * Les identifiants qu'un conteneur réclame sans les avoir.
     *
     * Le bundle seul ne compile pas : il référence légitimement des services que FrameworkBundle
     * fournit (`messenger.default_bus`, …). On déclare donc chaque manquant en synthétique et on
     * recommence, jusqu'à ce que la passe amont passe — ce qui reste est la liste exacte de ce
     * que le bundle attend de l'extérieur. Un service **à nous** dans cette liste est un bug.
     *
     * @return list<string>
     */
    private static function servicesManquants(ContainerBuilder $container): array
    {
        $manquants = [];
        $passe = new CheckExceptionOnInvalidReferenceBehaviorPass();

        for ($i = 0; $i < 100; ++$i) {
            try {
                $passe->process($container);

                return $manquants;
            } catch (ServiceNotFoundException $e) {
                $id = $e->getId();
                if (null === $id || \in_array($id, $manquants, true)) {
                    throw $e;
                }
                $manquants[] = $id;
                $container->register($id, \stdClass::class)->setSynthetic(true);
            }
        }

        self::fail('la passe de vérification ne converge pas');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(bool $debug, array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        (new DurableExtension())->load([$config], $container);

        return $container;
    }
}
