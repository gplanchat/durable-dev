<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Lock\Store\NullStore;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\Lock\Store\StoreFactory;

/**
 * Says what to configure when the resume lock has no factory.
 *
 * The DBAL backend has no server to serialise the tasks of one execution: `SingleResumeLockMiddleware`
 * does it, and without it two workers replay the same journal at the same time. Its factory is taken
 * from the application's container.
 *
 * Without `framework.lock` that service does not exist and compilation already fails — on a
 * "non-existent service" that names `lock.factory` and leaves the operator searching. What the
 * operator needs to know is not which service is missing, but which configuration section would have
 * provided it, and why it is not optional here.
 *
 * Checked in a pass rather than in the extension: when extensions load, the one that registers
 * `lock.factory` has not necessarily run yet, and an existence check there would answer false for a
 * correctly configured application.
 *
 * A factory that exists is not enough: its store must be shared between processes. `LOCK_DSN=flock`,
 * the Symfony default, is per-container, and two workers then replay the same journal at once (#259).
 * A literal DSN is refused here; one read from an env var is checked when the lock is built.
 *
 * ponytail: a `CombinedStore` (several stores for one resource) is not inspected.
 */
final class RequireLockFactoryPass implements CompilerPassInterface
{
    private const LOCK_SERVICE = 'durable.dbal.single_resume_lock';

    private const LOCAL_SCHEMES = ['flock', 'semaphore', 'in-memory', 'null'];

    private const LOCAL_STORES = [FlockStore::class, SemaphoreStore::class, InMemoryStore::class, NullStore::class];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::LOCK_SERVICE)) {
            return;
        }

        // The application may have redefined the service without arguments; fall back to the
        // conventional name rather than failing on the argument read.
        $arguments = $container->getDefinition(self::LOCK_SERVICE)->getArguments();
        $factory = (string) ($arguments[0] ?? 'lock.factory');

        if ($container->has($factory)) {
            if (!$container->hasParameter('durable.dbal.allow_local_lock') || !$container->getParameter('durable.dbal.allow_local_lock')) {
                $this->requireSharedStore($container, $factory);
            }

            return;
        }

        throw new \LogicException(\sprintf(
            'durable: the DBAL backend serialises the resumes of one execution with a lock, '
            . 'and the service "%s" that provides it does not exist. Enable the Lock component — '
            . '`framework.lock: true` in config/packages/framework.yaml, or a `framework.lock.resources` entry '
            . 'pointing at a store shared between your processes — or name your own factory in '
            . '`durable.dbal.lock_factory`. Without a lock, two workers replay the same journal at the same time.',
            $factory,
        ));
    }

    /**
     * Refuses a per-process store once it is built. Called by the container, for a store whose
     * DSN only the environment knows.
     *
     * @internal
     */
    public static function requireShared(PersistingStoreInterface $store): PersistingStoreInterface
    {
        foreach (self::LOCAL_STORES as $local) {
            if ($store instanceof $local) {
                throw new \LogicException(self::localStoreMessage((new \ReflectionClass($local))->getShortName()));
            }
        }

        return $store;
    }

    private function requireSharedStore(ContainerBuilder $container, string $factory): void
    {
        $arguments = $container->findDefinition($factory)->getArguments();
        $store = $arguments['index_0'] ?? $arguments[0] ?? null;
        if (!$store instanceof Reference || !$container->has((string) $store)) {
            return;
        }

        $definition = $container->findDefinition((string) $store);
        if (\in_array($definition->getClass(), self::LOCAL_STORES, true)) {
            throw new \LogicException(self::localStoreMessage((new \ReflectionClass((string) $definition->getClass()))->getShortName()));
        }

        $dsn = $definition->getArguments()[0] ?? null;
        if ([StoreFactory::class, 'createStore'] !== $definition->getFactory() || !\is_string($dsn)) {
            return;
        }

        $usedEnvs = [];
        $container->resolveEnvPlaceholders($dsn, null, $usedEnvs);
        if ([] === $usedEnvs) {
            if (\in_array(strtok($dsn, ':'), self::LOCAL_SCHEMES, true)) {
                throw new \LogicException(self::localStoreMessage(strtok($dsn, ':')));
            }

            return;
        }

        // A factory of its own around the checked store: the application's `lock.factory`, and
        // whatever else it locks, stay untouched.
        $container->register('durable.dbal.lock_store', PersistingStoreInterface::class)
            ->setFactory([self::class, 'requireShared'])
            ->setArguments([$store])
        ;
        $container->register('durable.dbal.lock_factory', LockFactory::class)
            ->setArguments([new Reference('durable.dbal.lock_store')])
        ;
        $container->getDefinition(self::LOCK_SERVICE)->replaceArgument(0, new Reference('durable.dbal.lock_factory'));
    }

    private static function localStoreMessage(string $store): string
    {
        return \sprintf(
            'durable: the lock store behind the DBAL resume lock is per-process (`%s`). Two workers could resume the same execution at once. '
            . 'Use a shared store — `framework.lock` set to a DBAL URL (`pgsql://…`, `mysql://…`, not a connection name), `redis://…` or `postgresql+advisory://…` — '
            . 'or set `durable.dbal.allow_local_lock: true` if you run exactly one worker.',
            $store,
        );
    }
}
