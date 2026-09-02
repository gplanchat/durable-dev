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

    public static function user(string $content): self
    {
        return new self('user', $content, []);
    }

    public function isSystem(): bool
    {
        return 'system' === $this->role;
    }

    public function isUser(): bool
    {
        return 'user' === $this->role;
    }

    /**
     * Ce qui a du sens à reprendre dans une nouvelle exécution : les tours parlés.
     *
     * Un `role: tool` et un message d'assistant qui ne porte que des `tool_calls` racontent la
     * mécanique d'un run qui s'achève ; les rejouer dans le suivant remplirait le fil de bulles
     * vides et le sac de références d'appels qui n'existent plus.
     */
    public function carriesText(): bool
    {
        return null !== $this->content && '' !== $this->content
            && ('user' === $this->role || 'assistant' === $this->role);
    }

    /**
     * La forme du fil, celle que porte déjà la charge d'un appel modèle — pas un format de plus.
     *
     * @return array{role: string, content: string}
     */
    public function toWire(): array
    {
        return ['role' => $this->role, 'content' => (string) $this->content];
    }

    /**
     * @param list<array<string, mixed>> $wire
     *
     * @return list<self>
     */
    public static function listFromWire(array $wire): array
    {
        return array_map(self::fromWire(...), array_values($wire));
    }

    /**
     * @param list<self> $messages
     *
     * @return list<array{role: string, content: string}>
     */
    public static function listToWire(array $messages): array
    {
        return array_map(static fn (self $message): array => $message->toWire(), array_values($messages));
    }

    /**
     * @return array{role: string, content: string|null, toolCalls: list<ToolCallRef>}
     */
    public function jsonSerialize(): array
    {
        return ['role' => $this->role, 'content' => $this->content, 'toolCalls' => $this->toolCalls];
    }
}
