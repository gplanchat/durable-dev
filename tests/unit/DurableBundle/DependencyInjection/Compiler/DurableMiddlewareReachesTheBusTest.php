<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\DurableBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Ce que le bundle promet insère-t-il vraiment quelque chose dans un bus.
 *
 * Le verrou de reprise du backend DBAL était enregistré avec `->addTag('messenger.middleware')`.
 * Cette balise n'existe pas dans Symfony : rien n'appelle `findTaggedServiceIds()` dessus, et
 * `UnusedTagsPass` ne la connaît pas. Le service était donc défini et **installé dans aucun bus**,
 * en silence, alors que la documentation promet qu'il l'est automatiquement et que c'est la seule
 * garde du backend contre deux reprises concurrentes de la même exécution — activités dupliquées,
 * journal forké.
 *
 * Le test ne nomme aucune passe : il joue celles que le bundle enregistre et regarde le paramètre
 * `<busId>.middleware`, qui est le seul endroit où Messenger lit sa pile. Une passe renommée ou
 * remplacée ne le fait pas passer par accident.
 *
 * @see DUR030
 */
final class DurableMiddlewareReachesTheBusTest extends TestCase
{
    public function testTheDbalResumeLockIsInstalledInTheBus(): void
    {
        $middleware = $this->middlewareOfBusAfterCompilation(['event_store' => ['type' => 'dbal']]);

        self::assertContains('durable.dbal.single_resume_lock', $middleware);
    }

    public function testTheProfilerMiddlewareStaysInstalled(): void
    {
        $middleware = $this->middlewareOfBusAfterCompilation(['event_store' => ['type' => 'dbal']]);

        self::assertContains('durable.messenger.middleware.workflow_run_dispatch_profiler', $middleware);
    }

    public function testWithoutTheDbalEventStoreNoLockIsInstalled(): void
    {
        $middleware = $this->middlewareOfBusAfterCompilation(['event_store' => ['type' => 'in_memory']]);

        self::assertNotContains('durable.dbal.single_resume_lock', $middleware);
    }

    public function testTheTraceableMiddlewareKeepsItsPlaceInFront(): void
    {
        $middleware = $this->middlewareOfBusAfterCompilation(
            ['event_store' => ['type' => 'dbal']],
            existing: [['id' => 'traceable'], ['id' => 'send_message']],
        );

        self::assertSame('traceable', $middleware[0]);
    }

    /**
     * @param array<string, mixed>                                    $config
     * @param list<array{id?: string, arguments?: array<int, mixed>}> $existing
     *
     * @return list<string>
     */
    private function middlewareOfBusAfterCompilation(array $config, array $existing = [['id' => 'send_message']]): array
    {
        $container = new ContainerBuilder();
        (new DurableExtension())->load([$config], $container);

        // Ce que FrameworkExtension pose pour chaque bus déclaré, et la seule chose que
        // MessengerPass relit ensuite.
        $container->register('messenger.bus.default')->addTag('messenger.bus');
        $container->setParameter('messenger.bus.default.middleware', $existing);

        (new DurableBundle())->build($container);
        foreach ($container->getCompilerPassConfig()->getBeforeOptimizationPasses() as $pass) {
            $pass->process($container);
        }

        /** @var list<array{id?: string}> $stack */
        $stack = $container->getParameter('messenger.bus.default.middleware');

        return array_values(array_filter(array_column($stack, 'id')));
    }
}
