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
 * **Sur quels bus.** Tous par défaut, et `durable.messenger.buses` permet de nommer les seuls qui
 * portent des messages durables. Le défaut ne peut pas être plus fin : le bundle ne sait pas quel
 * bus l'application a choisi pour router `ResumeWorkflowMessage`, et deviner retirerait le verrou
 * là où il fait son travail.
 *
 * L'ordre vient de l'attribut `priority`, décroissant : ce qui compte est que deux middlewares ne
 * dépendent pas de l'ordre d'itération du conteneur. Ils entrent en **tête** parce qu'un verrou
 * doit envelopper tout ce qui suit, y compris un `doctrine_transaction` — le relâcher avant le
 * commit rouvrirait la fenêtre qu'il ferme.
 */
final class RegisterDurableMiddlewarePass implements CompilerPassInterface
{
    public const TAG = 'durable.messenger.middleware';
    public const BUSES_PARAMETER = 'durable.messenger.buses';

    public function process(ContainerBuilder $container): void
    {
        $entries = $this->taggedMiddlewareIds($container);
        if ([] === $entries) {
            return;
        }

        foreach ($this->busesToServe($container) as $busId) {
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
     * Les bus où installer, et rien qu'eux.
     *
     * Le défaut reste **tous les bus** : c'est le comportement historique, et le restreindre de
     * notre propre chef retirerait le verrou de reprise du bus qui porte réellement les messages
     * durables chez quelqu'un — une perte de durabilité silencieuse, exactement ce contre quoi le
     * verrou existe.
     *
     * @return list<string>
     */
    private function busesToServe(ContainerBuilder $container): array
    {
        $declared = array_keys($container->findTaggedServiceIds('messenger.bus'));

        $chosen = $container->hasParameter(self::BUSES_PARAMETER)
            ? $container->getParameter(self::BUSES_PARAMETER)
            : [];

        if (!\is_array($chosen) || [] === $chosen) {
            return $declared;
        }

        // Un bus nommé qui n'existe pas est une faute de frappe, et la laisser passer produirait
        // le silence qu'on cherche à supprimer : la configuration a l'air posée, rien ne s'installe.
        $unknown = array_diff($chosen, $declared);
        if ([] !== $unknown) {
            throw new \LogicException(\sprintf(
                'durable.messenger.buses nomme %s, qui n\'est pas un bus Messenger de cette application. '
                . 'Bus déclarés : %s. Un identifiant de bus est un identifiant de service — '
                . '"messenger.bus.default" pour le bus par défaut de FrameworkBundle.',
                implode(', ', array_map(static fn(string $id): string => '"' . $id . '"', $unknown)),
                [] === $declared ? 'aucun' : implode(', ', $declared),
            ));
        }

        return array_values(array_intersect($declared, $chosen));
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
