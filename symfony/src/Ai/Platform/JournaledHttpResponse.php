<?php

declare(strict_types=1);

namespace App\Ai\Platform;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * La réponse HTTP telle que le journal la restitue — un 200 et un corps, rien d'autre.
 *
 * Les convertisseurs des ponts exigent une vraie `ResponseInterface` : celui de Mistral appelle
 * `throwOnHttpError($response)` avant de regarder les données. Au rejeu il n'y a plus de socket,
 * seulement ce que l'activité a rapporté — et ce qu'elle rapporte est toujours un succès, puisque
 * {@see \App\Ai\Activity\ModelInvocationActivityHandler} relève sur tout code >= 400.
 */
final readonly class JournaledHttpResponse implements ResponseInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private array $data,
    ) {
    }

    public function getStatusCode(): int
    {
        return 200;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(bool $throw = true): array
    {
        return ['content-type' => ['application/json']];
    }

    public function getContent(bool $throw = true): string
    {
        return json_encode($this->data, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $throw = true): array
    {
        return $this->data;
    }

    public function cancel(): void
    {
        // Rien à annuler : l'appel a eu lieu dans l'activité, peut-être il y a trois jours.
    }

    public function getInfo(?string $type = null): mixed
    {
        $info = ['http_code' => 200, 'url' => 'durable://journal'];

        return null === $type ? $info : ($info[$type] ?? null);
    }
}
