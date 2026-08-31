<?php

declare(strict_types=1);

namespace App\Ai\Activity;

use Gplanchat\Durable\Attribute\AsActivityHandler;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelClientInterface;

/**
 * Exécute l'appel modèle hors du workflow. Reçoit et rend des tableaux : le payload a été normalisé
 * côté workflow par `Contract::createRequestPayload()`, la réponse est le JSON du fournisseur.
 */
#[AsActivityHandler(contract: ModelInvocationActivityInterface::class)]
final class ModelInvocationActivityHandler implements ModelInvocationActivityInterface
{
    public function __construct(
        private readonly ModelClientInterface $client,
        private readonly ModelCatalogInterface $catalog = new FallbackModelCatalog(),
    ) {
    }

    public function invokeModel(string $model, array $payload, array $options): array
    {
        return $this->client->request($this->catalog->getModel($model), $payload, $options)->getData();
    }
}
