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
        public WatchSubject $subject,
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
        $subject = WatchSubject::tryFrom(trim((string) ($arguments['sujet'] ?? '')));
        if (null === $subject) {
            // Refus visible plutôt que veille morte : sans sujet connu, aucun événement ne pourra
            // jamais lever cette veille, et l'agent dormirait jusqu'à son échéance sans que rien
            // ne le signale.
            throw new UnknownWatchSubject(trim((string) ($arguments['sujet'] ?? '')));
        }

        return new self(
            $callId,
            $subject,
            trim((string) ($arguments['observation'] ?? '')),
            trim((string) ($arguments['intention'] ?? '')),
            $expiresAt,
        );
    }

    /**
     * @return array{callId: string, subject: string, observation: string, intention: string, expiresAt: float|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'callId' => $this->callId,
            'subject' => $this->subject->value,
            'observation' => $this->observation,
            'intention' => $this->intention,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
