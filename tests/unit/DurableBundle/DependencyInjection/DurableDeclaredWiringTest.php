<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RequireLockFactoryPass;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Ce que le bundle accepte alors qu'il ne peut pas le tenir.
 *
 * Trois formes du même défaut : une configuration reçue puis jetée, une dépendance dont l'absence
 * ne dit pas quoi faire, et une référence dure vers un paquet que personne ne déclare. Aucune ne
 * casse à l'installation — toutes se paient au premier incident, là où on ne cherche pas.
 */
final class DurableDeclaredWiringTest extends TestCase
{
    /**
     * Le pool est cherché par `hasDefinition()`. Un alias — et
     * `Psr\Cache\CacheItemPoolInterface` en est un — n'en est pas une, et une définition posée par
     * une extension qui tourne après celle-ci n'en est pas encore une. Dans les deux cas la
     * configuration de l'exploitant était silencieusement jetée.
     */
    public function testUnPoolConfigureEstCableMemeSaDefinitionAbsenteAuChargement(): void
    {
        $container = $this->load(['activity_contracts' => ['cache' => 'mon.pool.declare.plus.tard']]);

        $argument = $container->getDefinition(ActivityContractResolver::class)->getArgument(0);

        self::assertInstanceOf(
            Reference::class,
            $argument,
            "le pool configuré doit être référencé ; s'il n'existe pas, c'est une erreur de compilation, pas un silence",
        );
        self::assertSame('mon.pool.declare.plus.tard', (string) $argument);
    }

    public function testSansPoolConfigureLeResolveurNEnRecoitAucun(): void
    {
        $container = $this->load([]);

        self::assertNull($container->getDefinition(ActivityContractResolver::class)->getArgument(0));
    }

    /**
     * Sans `framework.lock`, `lock.factory` n'existe pas et le conteneur échoue — mais sur un
     * « service inexistant » qui ne dit pas quoi configurer. Le verrou est obligatoire sur DBAL :
     * sans lui, deux workers rejouent le même journal en même temps.
     */
    public function testLAbsenceDeLockFactoryDitQuoiConfigurer(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('durable.dbal.single_resume_lock', new Definition(\stdClass::class))
            ->setArguments([new Reference('lock.factory')]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/framework\.lock/');

        (new RequireLockFactoryPass())->process($container);
    }

    public function testAvecLockFactoryLaPasseLaisseFaire(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('durable.dbal.single_resume_lock', new Definition(\stdClass::class));
        $container->setDefinition('lock.factory', new Definition(\stdClass::class));

        (new RequireLockFactoryPass())->process($container);

        self::assertTrue($container->hasDefinition('durable.dbal.single_resume_lock'));
    }

    public function testSansBackendDbalLaPasseNeDitRien(): void
    {
        $container = new ContainerBuilder();

        (new RequireLockFactoryPass())->process($container);

        self::assertFalse($container->hasDefinition('lock.factory'));
    }

    /**
     * L'extension importe des classes des deux ponts. Un `composer require` du seul bundle donne
     * alors un conteneur qui compile et un fatal « class not found » au premier appel.
     */
    public function testLesDeuxPontsSontDeclaresEnSuggest(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../src/DurableBundle/composer.json'),
            true,
        );

        self::assertIsArray($manifest);
        $suggest = $manifest['suggest'] ?? [];

        foreach (['gplanchat/durable-bridge-temporal', 'gplanchat/durable-bridge-dbal'] as $bridge) {
            self::assertArrayHasKey($bridge, $suggest, $bridge . ' est câblé en dur par DurableExtension');
        }
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
