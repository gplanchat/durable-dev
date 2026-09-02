<?php

declare(strict_types=1);

namespace App\Ai\Watch;

/**
 * Une veille posée par l'agent : ce qu'il guette, et **ce qu'il comptait en faire**.
 *
 * L'intention est écrite au moment de l'inscription, pas reconstruite au réveil. C'est tout
 * l'intérêt : au réveil — dans une heure, dans trois jours, après un redéploiement — l'agent n'a
 * pas à se souvenir, le journal le lui dit.
 */
final readonly class Watch implements \JsonSerializable
{
    public function __construct(
        public string $callId,
        public string $observation,
        public string $intention,
        public ?float $expiresAt = null,
    ) {
    }

    /**
     * Fabrique de frontière : les arguments viennent du modèle, rien ne garantit le schéma.
     *
     * @param array<string, mixed> $arguments
     */
    public static function fromArguments(string $callId, array $arguments, ?float $expiresAt = null): self
    {
        return new self(
            $callId,
            trim((string) ($arguments['observation'] ?? '')),
            trim((string) ($arguments['intention'] ?? '')),
            $expiresAt,
        );
    }

    /**
     * @return array{callId: string, observation: string, intention: string, expiresAt: float|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'callId' => $this->callId,
            'observation' => $this->observation,
            'intention' => $this->intention,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
