<?php

declare(strict_types=1);

namespace App\Ai\Question;

/**
 * Le guichet des questions en attente. État de workflow, reconstruit par rejeu depuis les signaux
 * journalisés — jamais lu d'un stockage à côté.
 *
 * Jumeau de {@see \App\Ai\Guard\ToolApprovalGate} par la forme, pas par le rôle : la porte laisse
 * passer ou non ce que le modèle a décidé, le guichet lui rapporte ce qu'il ne savait pas.
 */
final class HumanQuestionDesk
{
    /** @var array<string, list<string>> id d'appel → réponses retenues */
    private array $answers = [];

    /** @var array<string, PendingQuestion> */
    private array $pending = [];

    public function ask(PendingQuestion $question): void
    {
        $this->pending[$question->callId] = $question;
    }

    /**
     * @param list<string> $answers
     */
    public function answer(string $callId, array $answers): void
    {
        // La première réponse gagne : un second signal arrivé après l'échéance ne doit pas
        // rouvrir une question déjà close, sinon l'ordre du journal donnerait le verdict inverse
        // au rejeu.
        $this->answers[$callId] ??= array_values(array_filter(
            array_map(static fn (mixed $answer): string => trim((string) $answer), $answers),
            static fn (string $answer): bool => '' !== $answer,
        ));
        unset($this->pending[$callId]);
    }

    public function isAnswered(string $callId): bool
    {
        return \array_key_exists($callId, $this->answers);
    }

    /**
     * @return list<string>
     */
    public function answersOf(string $callId): array
    {
        return $this->answers[$callId] ?? [];
    }

    /**
     * @return list<PendingQuestion>
     */
    public function pending(): array
    {
        return array_values($this->pending);
    }
}
