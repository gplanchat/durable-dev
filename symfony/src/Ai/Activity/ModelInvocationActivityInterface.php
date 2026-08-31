<?php

declare(strict_types=1);

namespace App\Ai\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * Le seul endroit du prototype qui parle au fournisseur. Tout traverse la frontière en tableaux
 * bruts : `Contract::createRequestPayload()` a déjà normalisé la conversation côté workflow, et le
 * fournisseur répond du JSON. Rien à mapper, donc rien à faire diverger.
 */
interface ModelInvocationActivityInterface
{
    /**
     * @param array<string|int, mixed> $payload
     * @param array<string, mixed>     $options
     *
     * @return array<string, mixed>
     */
    #[AsActivityMethod('ai_model_invoke')]
    public function invokeModel(string $model, array $payload, array $options): array;
}
