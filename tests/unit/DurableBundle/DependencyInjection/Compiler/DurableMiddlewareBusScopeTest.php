<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection\Compiler;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\DurableBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Sur quels bus les middlewares du bundle s'installent.
 *
 * Ils entraient en tête de **tous** les bus de l'application, sans échappatoire. Le verrou de
 * reprise du backend DBAL prend un verrou par exécution et le middleware de profil mesure : les
 * appliquer au bus de commandes métier d'une application, qui ne transporte aucun message durable,
 * c'est du travail et un point de contention que personne n'a demandés.
 *
 * Le défaut n'est pas d'être en tête — un verrou doit envelopper ce qui suit, y compris un
 * `doctrine_transaction` — ni d'être posés par une passe, faute de balise `messenger.middleware`
 * chez Symfony. Il est de ne pas pouvoir dire « ceux-là seulement ».
 */
final class DurableMiddlewareBusScopeTest extends TestCase
{
    public function testSansConfigurationTousLesBusSontServisCommeAvant(): void
    {
        $container = $this->compile([], ['messenger.bus.commandes', 'messenger.bus.durable']);

        self::assertContains('durable.dbal.single_resume_lock', $this->stackOf($container, 'messenger.bus.commandes'));
        self::assertContains('durable.dbal.single_resume_lock', $this->stackOf($container, 'messenger.bus.durable'));
    }

    public function testUneListeRestreintLesBusServis(): void
    {
        $container = $this->compile(
            ['messenger' => ['buses' => ['messenger.bus.durable']]],
            ['messenger.bus.commandes', 'messenger.bus.durable'],
        );

        self::assertNotContains(
            'durable.dbal.single_resume_lock',
            $this->stackOf($container, 'messenger.bus.commandes'),
            "le bus de commandes métier ne transporte aucun message durable : rien n'a à s'y installer",
        );
        self::assertContains(
            'durable.dbal.single_resume_lock',
            $this->stackOf($container, 'messenger.bus.durable'),
        );
    }

    /**
     * Une liste qui nomme un bus inexistant ne doit pas produire un silence : c'est exactement la
     * faute qu'on croit avoir corrigée alors que rien ne s'est installé.
     */
    public function testUnBusNommeQuiNExistePasEstRefuse(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/messenger\.bus\.faute_de_frappe/');

        $this->compile(
            ['messenger' => ['buses' => ['messenger.bus.faute_de_frappe']]],
            ['messenger.bus.durable'],
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string>         $buses
     */
    private function compile(array $config, array $buses): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);
        (new DurableExtension())->load([$config + ['event_store' => ['type' => 'dbal']]], $container);

        // Ce que FrameworkExtension pose : un bus déclaré, et sa pile dans un paramètre.
        foreach ($buses as $busId) {
            $container->register($busId)->addTag('messenger.bus');
            $container->setParameter($busId . '.middleware', [['id' => 'send_message']]);
        }
        // Et ce que `framework.lock` pose, dont le backend DBAL a besoin.
        $container->register('lock.factory', \stdClass::class);

        (new DurableBundle())->build($container);
        foreach ($container->getCompilerPassConfig()->getBeforeOptimizationPasses() as $pass) {
            $pass->process($container);
        }

        return $container;
    }

    /**
     * @return list<string>
     */
    private function stackOf(ContainerBuilder $container, string $busId): array
    {
        /** @var list<array{id?: string}> $stack */
        $stack = $container->getParameter($busId . '.middleware');

        return array_values(array_filter(array_column($stack, 'id')));
    }
}
