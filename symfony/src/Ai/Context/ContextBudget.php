<?php

declare(strict_types=1);

namespace App\Ai\Context;

/**
 * Le budget de contexte d'une instance d'agent, et la façon de le tenir.
 *
 * Une conversation durable grossit sans fin — c'est le prix du journal. Sans borne, elle finit
 * par dépasser la fenêtre du modèle, et ce jour-là l'agent ne rate pas un tour : il ne peut plus
 * en faire un seul. Le budget est donc une garde, au même titre que celle des outils, mais posée
 * sur l'autre jambe : l'appel modèle.
 *
 * **Pure par construction.** La compaction tourne en code workflow, donc elle est rejouée : deux
 * exécutions sur la même conversation doivent rendre exactement le même payload. Pas d'horloge,
 * pas de hasard, pas d'appel au modèle pour résumer — un résumé produit par un modèle serait une
 * seconde source de non-déterminisme là où on essaie justement d'en supprimer.
 */
final readonly class ContextBudget
{
    /**
     * Quatre caractères par jeton : l'ordre de grandeur usuel sur du texte latin.
     *
     * ponytail: heuristique, pas une mesure. Elle sous-estime le code et les langues non latines.
     * Le vrai compte demande le tokeniseur du modèle — à brancher le jour où la marge ne suffit
     * plus, et c'est à ça que sert la réserve.
     */
    private const CHARS_PER_TOKEN = 4;

    public function __construct(
        public int $maxTokens = 24_000,
        /**
         * Ce qu'on garde pour la réponse et pour l'imprécision de l'estimation. Un budget tenu à
         * l'octet près serait dépassé au premier mot mal compté.
         */
        public float $reserve = 0.25,
    ) {
        if ($maxTokens < 1) {
            throw new \InvalidArgumentException('Un budget de contexte doit être positif.');
        }
    }

    public function halved(): self
    {
        return new self(max(1, intdiv($this->maxTokens, 2)), $this->reserve);
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function estimate(array $messages): int
    {
        return intdiv(mb_strlen(json_encode($messages, \JSON_UNESCAPED_UNICODE) ?: ''), self::CHARS_PER_TOKEN);
    }

    public function ceiling(): int
    {
        return (int) ($this->maxTokens * (1 - $this->reserve));
    }

    /**
     * Ramène la conversation sous le plafond en abandonnant les tours les plus anciens.
     *
     * Le découpage en tours vit dans {@see Conversation} : ici on ne fait qu'en retirer par le
     * début tant que ça dépasse. C'est {@see Turn} qui garantit qu'un résultat d'outil ne se
     * retrouve jamais sans l'appel qui l'a produit.
     *
     * ponytail: plafond assumé — un tour à lui seul plus gros que la fenêtre ne peut pas être
     * compacté, puisque abandonner le message auquel il faut répondre n'aurait pas de sens. Il
     * part tel quel, le fournisseur le refuse, et c'est le chemin réactif qui reprend la main.
     * Le résumé par le modèle serait la sortie — mais il n'est pas déterministe, donc il ne peut
     * pas vivre ici : sa place est dans un `continueAsNew`, où il devient une charge de départ.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    public function fit(array $messages): array
    {
        if ($this->estimate($messages) <= $this->ceiling()) {
            return $messages;
        }

        $conversation = Conversation::fromWire($messages);
        $avant = $conversation->messageCount();

        while (!$conversation->hasSingleTurn() && $this->estimate($conversation->toWire()) > $this->ceiling()) {
            $conversation = $conversation->withoutOldestTurn();
        }

        $retires = $avant - $conversation->messageCount();
        if (0 === $retires) {
            return $messages;
        }

        return $conversation->withNotice(\sprintf(
            '[%d messages plus anciens ont été retirés du contexte pour tenir dans la fenêtre du '
            .'modèle. Si une information manque, demande-la plutôt que de l\'inventer.]',
            $retires,
        ))->toWire();
    }
}
