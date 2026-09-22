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
        public string $callId,
        public string $tool,
        public array $arguments,
        public ?string $result,
    ) {
    }

    public function withResult(?string $result): self
    {
        return new self($this->callId, $this->tool, $this->arguments, $result);
    }

    /**
     * `callId` sort jusqu'au fil : c'est lui qui permet d'apparier un appel affiché à l'activité
     * qui l'exécute, donc de dire « celle-ci tourne encore ». Apparier sur le nom et les arguments
     * marcherait jusqu'au premier agent qui appelle deux fois le même outil pareil.
     *
     * @return array{callId: string, tool: string, arguments: array<string, mixed>, result: string|null}
     */
    public function jsonSerialize(): array
    {
        return ['callId' => $this->callId, 'tool' => $this->tool, 'arguments' => $this->arguments, 'result' => $this->result];
    }
}
