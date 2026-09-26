<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Bridge\Dbal\Messenger\SingleResumeLockMiddleware;
use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RequireLockFactoryPass;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\StoreFactory;

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
     * `LOCK_DSN=flock` is the Symfony default, and it is per-container: two workers in two
     * containers both hold `durable-resume-{id}` and replay the same journal at once (#259).
     */
    public function testAPerProcessLockStoreIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/per-process \(`flock`\).*durable\.dbal\.allow_local_lock: true/');

        (new RequireLockFactoryPass())->process($this->containerWithLockStore('flock'));
    }

    public function testASingleWorkerMayOptIntoALocalLock(): void
    {
        $container = $this->containerWithLockStore('semaphore');
        $container->setParameter('durable.dbal.allow_local_lock', true);

        (new RequireLockFactoryPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function testASharedLockStorePasses(): void
    {
        (new RequireLockFactoryPass())->process($this->containerWithLockStore('postgresql+advisory://app@db/app'));

        $this->addToAssertionCount(1);
    }

    /**
     * `%env(LOCK_DSN)%` is only known at runtime: the store is checked when the resume lock is built,
     * the first time a worker takes a durable message.
     */
    public function testALockStoreFromAnEnvVarIsCheckedWhenTheLockIsBuilt(): void
    {
        $container = $this->containerWithLockStore('%env(LOCK_DSN)%');
        $container->setParameter('env(LOCK_DSN)', 'flock');
        $container->addCompilerPass(new RequireLockFactoryPass());
        $container->compile(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/per-process \(`FlockStore`\)/');

        $container->get('durable.dbal.single_resume_lock');
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
     * What FrameworkExtension registers for `framework.lock: <dsn>`.
     */
    private function containerWithLockStore(string $dsn): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('.lock.default.store', PersistingStoreInterface::class)
            ->setFactory([StoreFactory::class, 'createStore'])
            ->setArguments([$container->getParameterBag()->resolveValue($dsn)]);
        $container->register('lock.factory.abstract', LockFactory::class)->setArguments([null])->setAbstract(true);
        $container->setDefinition('lock.default.factory', new ChildDefinition('lock.factory.abstract'))
            ->replaceArgument(0, new Reference('.lock.default.store'));
        $container->setAlias('lock.factory', 'lock.default.factory');
        $container->register('durable.dbal.single_resume_lock', SingleResumeLockMiddleware::class)
            ->setArguments([new Reference('lock.factory'), 300.0])
            ->setPublic(true);

        return $container;
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
