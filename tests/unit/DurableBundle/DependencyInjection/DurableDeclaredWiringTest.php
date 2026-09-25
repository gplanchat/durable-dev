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
 * What the bundle accepts while it cannot honour it.
 *
 * Three shapes of the same defect: a configuration received then discarded, a dependency whose
 * absence does not say what to do, and a hard reference to a package nobody declares. None breaks
 * at install time — all are paid at the first incident, where nobody is looking.
 */
final class DurableDeclaredWiringTest extends TestCase
{
    /**
     * The pool is looked up with `hasDefinition()`. An alias — and `Psr\Cache\CacheItemPoolInterface`
     * is one — is not a definition, and neither yet is a definition placed by an extension that runs
     * after this one. In both cases the operator's configuration was silently discarded.
     */
    public function testAConfiguredPoolIsWiredEvenWhenItsDefinitionIsAbsentAtLoadTime(): void
    {
        $container = $this->load(['activity_contracts' => ['cache' => 'my.pool.declared.later']]);

        $argument = $container->findDefinition(ActivityContractResolver::class)->getArgument(0);

        self::assertInstanceOf(
            Reference::class,
            $argument,
            'the configured pool must be referenced; if it does not exist, that is a compile error, not silence',
        );
        self::assertSame('my.pool.declared.later', (string) $argument);
    }

    public function testWithoutAConfiguredPoolTheResolverReceivesNone(): void
    {
        $container = $this->load([]);

        self::assertNull($container->findDefinition(ActivityContractResolver::class)->getArgument(0));
    }

    /**
     * Without `framework.lock`, `lock.factory` does not exist and the container fails — but on a
     * "non-existent service" that does not say what to configure. The lock is mandatory on DBAL:
     * without it, two workers replay the same journal at the same time.
     */
    public function testAMissingLockFactorySaysWhatToConfigure(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('durable.dbal.single_resume_lock', new Definition(\stdClass::class))
            ->setArguments([new Reference('lock.factory')]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/framework\.lock/');

        (new RequireLockFactoryPass())->process($container);
    }

    public function testWithALockFactoryThePassLetsItThrough(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('durable.dbal.single_resume_lock', new Definition(\stdClass::class));
        $container->setDefinition('lock.factory', new Definition(\stdClass::class));

        (new RequireLockFactoryPass())->process($container);

        self::assertTrue($container->hasDefinition('durable.dbal.single_resume_lock'));
    }

    public function testWithoutTheDbalBackendThePassSaysNothing(): void
    {
        $container = new ContainerBuilder();

        (new RequireLockFactoryPass())->process($container);

        self::assertFalse($container->hasDefinition('lock.factory'));
    }

    /**
     * The extension imports classes from both bridges. A `composer require` of the bundle alone then
     * yields a container that compiles and a "class not found" fatal at the first call.
     */
    public function testBothBridgesAreDeclaredInSuggest(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../src/DurableBundle/composer.json'),
            true,
        );

        self::assertIsArray($manifest);
        $suggest = $manifest['suggest'] ?? [];

        foreach (['gplanchat/durable-bridge-temporal', 'gplanchat/durable-bridge-dbal'] as $bridge) {
            self::assertArrayHasKey($bridge, $suggest, $bridge . ' is hard-wired by DurableExtension');
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
