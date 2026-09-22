<?php

declare(strict_types=1);

namespace App\Ai\Question;

/**
 * Une réponse proposée. Le libellé est ce que l'humain clique et ce que le modèle relit — il fait
 * donc office d'identifiant, et il doit se suffire à lui-même.
 */
final readonly class QuestionOption implements \JsonSerializable
{
    public function __construct(
        public string $label,
        public string $description = '',
    ) {
        if ('' === trim($label)) {
            throw new \InvalidArgumentException('Une option doit porter un libellé.');
        }
    }

    /**
     * @param array<string, mixed> $wire
     */
    public static function fromWire(array $wire): self
    {
        return new self((string) ($wire['label'] ?? ''), (string) ($wire['description'] ?? ''));
    }

    /**
     * @return array{label: string, description: string}
     */
    public function jsonSerialize(): array
    {
        return ['label' => $this->label, 'description' => $this->description];
    }
}
