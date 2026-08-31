<?php

declare(strict_types=1);

namespace App\Ai\Chat;

/**
 * Un appel d'outil effectivement exécuté, avec son résultat quand l'activité est revenue.
 */
final readonly class ToolStep implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $tool,
        public array $arguments,
        public ?string $result,
    ) {
    }

    public function withResult(?string $result): self
    {
        return new self($this->tool, $this->arguments, $result);
    }

    /**
     * @return array{tool: string, arguments: array<string, mixed>, result: string|null}
     */
    public function jsonSerialize(): array
    {
        return ['tool' => $this->tool, 'arguments' => $this->arguments, 'result' => $this->result];
    }
}
