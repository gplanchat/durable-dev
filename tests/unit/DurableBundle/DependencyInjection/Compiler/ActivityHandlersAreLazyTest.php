<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection\Compiler;

use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\DurableBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use unit\DurableBundle\Fixtures\CompteurDInstances;
use unit\DurableBundle\Fixtures\PremierHandler;
use unit\DurableBundle\Fixtures\SecondHandler;

/**
 * Exécuter une activité ne doit construire que son gestionnaire.
 *
 * L'exécuteur recevait ses gestionnaires sous forme de callables `[Reference, '__invoke']`. Pour
 * bâtir ce tableau, le conteneur doit résoudre chaque référence : il instancie donc **tous** les
 * gestionnaires de l'application — et leurs dépendances, connexions et clients HTTP compris — pour
 * en appeler un seul.
 *
 * Ce que ça coûte ne se voit pas en développement, où les gestionnaires sont légers. Ça se voit sur
 * un worker qui traite une activité par message, avec vingt contrats déclarés.
 */
final class ActivityHandlersAreLazyTest extends TestCase
{
    protected function setUp(): void
    {
        CompteurDInstances::reset();
    }

    public function testUneSeuleActiviteExecuteeNeConstruitQueSonGestionnaire(): void
    {
        $container = $this->compile();

        /** @var ActivityExecutor $executor */
        $executor = $container->get(ActivityExecutor::class);

        self::assertSame(
            0,
            CompteurDInstances::total(),
            'obtenir l\'exécuteur ne doit construire aucun gestionnaire',
        );

        $executor->execute('premier.faire', ['quoi' => 'ceci']);

        self::assertSame(['premier'], CompteurDInstances::construits());
    }

    public function testLesDeuxGestionnairesRestentJoignables(): void
    {
        $container = $this->compile();

        /** @var ActivityExecutor $executor */
        $executor = $container->get(ActivityExecutor::class);

        self::assertSame('premier:ceci', $executor->execute('premier.faire', ['quoi' => 'ceci']));
        self::assertSame('second:cela', $executor->execute('second.faire', ['quoi' => 'cela']));
        self::assertSame(['premier', 'second'], CompteurDInstances::construits());
    }

    public function testUneActiviteInconnueEchoueToujoursClairement(): void
    {
        $container = $this->compile();

        /** @var ActivityExecutor $executor */
        $executor = $container->get(ActivityExecutor::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/inconnue/');

        $executor->execute('inconnue', []);
    }

    private function compile(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        (new DurableExtension())->load([[]], $container);
        $container->register('messenger.default_bus', \stdClass::class)->setPublic(true);

        foreach ([PremierHandler::class, SecondHandler::class] as $class) {
            $container->register($class, $class)->setAutoconfigured(true)->setPublic(false);
        }


        (new DurableBundle())->build($container);
        $container->compile();

        return $container;
    }
}
