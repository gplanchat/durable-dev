<?php

declare(strict_types=1);

namespace App\Ai\Context;

/**
 * Un tour de conversation : le message de l'humain, et tout ce que l'agent a produit en réponse —
 * ses appels d'outils, leurs résultats, sa réponse finale.
 *
 * C'est **l'unité indivisible de la compaction**. Un message `assistant` qui demande des outils et
 * les `tool` qui lui répondent forment un bloc : couper au milieu laisse un résultat orphelin, que
 * les fournisseurs refusent. Faire du tour un type plutôt qu'une convention rend la faute
 * impossible à commettre par distraction.
 *
 * Les messages eux-mêmes restent des tableaux : c'est du fil, la forme exacte appartient au
 * fournisseur, et la retyper reviendrait à réécrire son protocole.
 */
final readonly class Turn
{
    /**
     * @param non-empty-list<array<string, mixed>> $messages
     */
    private function __construct(
        public array $messages,
    ) {
    }

    /**
     * @param non-empty-list<array<string, mixed>> $messages
     */
    public static function of(array $messages): self
    {
        if ([] === $messages) {
            throw new \InvalidArgumentException('Un tour porte au moins un message.');
        }

        return new self($messages);
    }

    public function count(): int
    {
        return \count($this->messages);
    }
}
