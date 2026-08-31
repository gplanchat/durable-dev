<?php

declare(strict_types=1);

namespace App\Ai\Chat;

/**
 * Un message du fil. Le `role` reste la chaîne du fournisseur : c'est le vocabulaire du protocole,
 * pas du domaine, et un rôle inconnu ne doit pas faire échouer la lecture du journal.
 */
final readonly class TranscriptMessage implements \JsonSerializable
{
    /**
     * @param list<ToolCallRef> $toolCalls
     */
    private function __construct(
        public string $role,
        public ?string $content,
        public array $toolCalls,
    ) {
    }

    /**
     * @param array<string, mixed> $wire
     */
    public static function fromWire(array $wire): self
    {
        return new self(
            (string) ($wire['role'] ?? 'assistant'),
            null !== ($wire['content'] ?? null) ? (string) $wire['content'] : null,
            array_map(ToolCallRef::fromWire(...), $wire['tool_calls'] ?? []),
        );
    }

    public static function assistant(string $content): self
    {
        return new self('assistant', $content, []);
    }

    public function isSystem(): bool
    {
        return 'system' === $this->role;
    }

    /**
     * @return array{role: string, content: string|null, toolCalls: list<ToolCallRef>}
     */
    public function jsonSerialize(): array
    {
        return ['role' => $this->role, 'content' => $this->content, 'toolCalls' => $this->toolCalls];
    }
}
