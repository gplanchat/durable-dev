<?php

declare(strict_types=1);

namespace App\Ai\Context;

/**
 * La conversation telle que la compaction la voit : un préambule système, puis des tours.
 *
 * Le tableau plat qui part au fournisseur ne dit pas ses invariants — que le message système
 * ouvre, qu'un résultat d'outil suit toujours son appel. Ici ils sont portés par la structure,
 * et {@see ContextBudget} n'a plus qu'à retirer des tours par le début.
 *
 * Fabrique de frontière dans les deux sens : `fromWire()` à l'entrée, `toWire()` à la sortie. Le
 * fil reste le fil, mais il ne traverse plus le code.
 */
final readonly class Conversation
{
    /**
     * @param list<array<string, mixed>> $system
     * @param list<Turn>                 $turns
     */
    private function __construct(
        public array $system,
        public array $turns,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public static function fromWire(array $messages): self
    {
        $system = [];
        $turns = [];
        $current = [];

        foreach ($messages as $message) {
            // Seuls les messages système *de tête* sont le préambule : un marqueur de compaction
            // posé plus loin appartient au tour qu'il précède.
            if ('system' === ($message['role'] ?? null) && [] === $turns && [] === $current) {
                $system[] = $message;

                continue;
            }

            if ('user' === ($message['role'] ?? null) && [] !== $current) {
                $turns[] = Turn::of($current);
                $current = [];
            }

            $current[] = $message;
        }

        if ([] !== $current) {
            $turns[] = Turn::of($current);
        }

        return new self($system, $turns);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toWire(): array
    {
        $messages = $this->system;
        foreach ($this->turns as $turn) {
            foreach ($turn->messages as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Le dernier tour ne part jamais : sans lui il ne resterait rien à quoi répondre.
     */
    public function withoutOldestTurn(): self
    {
        if (\count($this->turns) <= 1) {
            return $this;
        }

        $turns = $this->turns;
        array_shift($turns);

        return new self($this->system, array_values($turns));
    }

    public function hasSingleTurn(): bool
    {
        return \count($this->turns) <= 1;
    }

    public function messageCount(): int
    {
        return \count($this->system) + array_sum(array_map(static fn (Turn $t): int => $t->count(), $this->turns));
    }

    public function withNotice(string $notice): self
    {
        return new self([...$this->system, ['role' => 'system', 'content' => $notice]], $this->turns);
    }
}
