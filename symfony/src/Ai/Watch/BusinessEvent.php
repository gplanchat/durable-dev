<?php

declare(strict_types=1);

namespace App\Ai\Watch;

/**
 * Un fait de l'application, dit dans le vocabulaire que les veilles connaissent.
 *
 * `details` est libre : il n'entre pas dans l'appariement — c'est le `subject` qui apparie — mais
 * il fait le texte que l'agent relira au réveil, à la place d'un résultat d'outil. Un objet plutôt
 * qu'un tableau, parce que c'est ce qui traverse la frontière entre le métier et l'agent.
 */
final readonly class BusinessEvent
{
    /**
     * @param array<string, scalar|null> $details
     */
    public function __construct(
        public WatchSubject $subject,
        public array $details = [],
    ) {
    }

    /**
     * Ce que l'agent lit au réveil. Pas de JSON : c'est un modèle qui va le relire.
     */
    public function describe(): string
    {
        if ([] === $this->details) {
            return $this->subject->describe();
        }

        return \sprintf(
            '%s (%s)',
            $this->subject->describe(),
            implode(', ', array_map(
                static fn (string $key, mixed $value): string => \sprintf('%s : %s', $key, var_export($value, true)),
                array_keys($this->details),
                $this->details,
            )),
        );
    }
}
