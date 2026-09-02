<?php

declare(strict_types=1);

namespace App\Ai\Question;

/**
 * Une question posée à l'humain, en attente de sa réponse.
 *
 * C'est l'inverse de la garde : celle-ci décide si un outil que le modèle a choisi peut partir,
 * celle-là est un outil dont le seul effet est d'aller chercher une information que le modèle n'a
 * pas. Même primitive — un signal et une attente — deux intentions.
 */
final readonly class PendingQuestion implements \JsonSerializable
{
    /**
     * @param list<QuestionOption> $options
     * @param float|null           $expiresAt instant (epoch) où l'échéance répondra « rien » à la
     *                                        place de l'humain
     */
    public function __construct(
        public string $callId,
        public string $question,
        public string $header,
        public array $options,
        public bool $multiSelect = false,
        public ?float $expiresAt = null,
    ) {
    }

    /**
     * Fabrique de frontière : les arguments viennent du modèle, donc en tableaux, et rien ne
     * garantit qu'il ait respecté le schéma. Une option sans libellé est jetée plutôt que de
     * faire échouer le tour.
     *
     * @param array<string, mixed> $arguments
     */
    public static function fromArguments(string $callId, array $arguments, ?float $expiresAt = null): self
    {
        $proposed = \is_array($arguments['options'] ?? null) ? $arguments['options'] : [];
        $options = [];
        foreach ($proposed as $option) {
            if (\is_array($option) && '' !== trim((string) ($option['label'] ?? ''))) {
                $options[] = QuestionOption::fromWire($option);
            }
        }

        return new self(
            $callId,
            (string) ($arguments['question'] ?? ''),
            (string) ($arguments['header'] ?? ''),
            $options,
            (bool) ($arguments['multiSelect'] ?? false),
            $expiresAt,
        );
    }

    public function expiringAt(?float $expiresAt): self
    {
        return new self($this->callId, $this->question, $this->header, $this->options, $this->multiSelect, $expiresAt);
    }

    /**
     * @return array{callId: string, question: string, header: string, options: list<QuestionOption>, multiSelect: bool, expiresAt: float|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'callId' => $this->callId,
            'question' => $this->question,
            'header' => $this->header,
            'options' => $this->options,
            'multiSelect' => $this->multiSelect,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
