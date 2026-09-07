<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Inserts the bundle's middleware at the head of every Messenger bus.
 *
 * Messenger only reads its stack from the "busId".middleware parameter, set by FrameworkExtension
 * and read back by MessengerPass. **There is no `messenger.middleware` tag**: nothing calls
 * `findTaggedServiceIds()` on it and `UnusedTagsPass` does not know it. A service that carries it
 * is defined and never installed, silently — that is what happened to the DBAL backend's resume
 * lock, the only guard against two concurrent resumes of the same execution.
 *
 * Hence a tag that belongs to the bundle, `durable.messenger.middleware`, and this pass to consume
 * it. The next middleware of the bundle installs itself by adding it, without a thought.
 *
 * The order comes from the `priority` attribute, descending: what matters is that two middleware
 * do not depend on the container's iteration order. They go in at the **head** because a lock must
 * wrap everything that follows, including a `doctrine_transaction` — releasing it before the
 * commit would reopen the window it closes.
 */
final class RegisterDurableMiddlewarePass implements CompilerPassInterface
{
    public const TAG = 'durable.messenger.middleware';

    public function process(ContainerBuilder $container): void
    {
        $entries = $this->taggedMiddlewareIds($container);
        if ([] === $entries) {
            return;
        }

        foreach (array_keys($container->findTaggedServiceIds('messenger.bus')) as $busId) {
            $param = $busId . '.middleware';
            if (!$container->hasParameter($param)) {
                continue;
            }

            $middleware = $container->getParameter($param);
            if (!\is_array($middleware)) {
                continue;
            }

            // `traceable` measures the bus; leaving it at the head keeps its measurements whole.
            $at = $this->isTraceableFirst($middleware) ? 1 : 0;
            array_splice($middleware, $at, 0, array_map(
                static fn(string $id): array => ['id' => $id],
                $entries,
            ));

            $container->setParameter($param, $middleware);
        }
    }

    /**
     * @return list<string>
     */
    private function taggedMiddlewareIds(ContainerBuilder $container): array
    {
        $byPriority = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $byPriority[] = [$tags[0]['priority'] ?? 0, $id];
        }

        // Descending priority, then id: two middleware of the same priority keep a stable order from
        // one compilation to the next.
        usort($byPriority, static fn(array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_column($byPriority, 1);
    }

    /**
     * @param list<array{id?: string, arguments?: array<int, mixed>}> $middleware
     */
    private function isTraceableFirst(array $middleware): bool
    {
        return isset($middleware[0]['id']) && 'traceable' === $middleware[0]['id'];
    }
}
