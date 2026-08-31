<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Insère les middlewares du bundle en tête de chaque bus Messenger.
 *
 * Messenger ne lit sa pile que dans le paramètre « busId ».middleware, posé par FrameworkExtension
 * et relu par MessengerPass. **Il n'existe pas de balise `messenger.middleware`** : rien n'appelle
 * `findTaggedServiceIds()` dessus et `UnusedTagsPass` ne la connaît pas. Un service qui la porte
 * est défini et jamais installé, en silence — c'est ce qui est arrivé au verrou de reprise du
 * backend DBAL, seule garde contre deux reprises concurrentes de la même exécution.
 *
 * D'où une balise qui appartient au bundle, `durable.messenger.middleware`, et cette passe pour la
 * consommer. Le prochain middleware du bundle s'installe en la posant, sans y penser.
 *
 * L'ordre vient de l'attribut `priority`, décroissant : ce qui compte est que deux middlewares ne
 * dépendent pas de l'ordre d'itération du conteneur. Ils entrent en **tête** parce qu'un verrou
 * doit envelopper tout ce qui suit, y compris un `doctrine_transaction` — le relâcher avant le
 * commit rouvrirait la fenêtre qu'il ferme.
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

            // `traceable` mesure le bus ; le laisser en tête garde ses mesures complètes.
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

        // Priorité décroissante, puis identifiant : deux middlewares de même priorité gardent un
        // ordre stable d'une compilation à l'autre.
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
