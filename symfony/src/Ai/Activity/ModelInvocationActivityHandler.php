<?php

declare(strict_types=1);

namespace App\Ai\Activity;

use Gplanchat\Durable\Attribute\AsActivityHandler;
use App\Ai\Context\ContextOverflow;
use Symfony\AI\Platform\Bridge\Mistral\ModelCatalog;
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
        private readonly ModelCatalogInterface $catalog = new ModelCatalog(),
    ) {
    }

    public function invokeModel(string $model, array $payload, array $options): array
    {
        $raw = $this->client->request($this->catalog->getModel($model), $payload, $options);

        // L'erreur HTTP appartient à l'activité, pas au code workflow : un 429 ou un 503 doit
        // rencontrer la politique de retentative de Durable (DUR011), pas être journalisé comme
        // un succès puis faire s'étrangler le convertisseur au rejeu.
        $response = $raw->getObject();
        $data = $raw->getData();

        // Un dépassement de fenêtre n'est pas une panne : c'est une réponse. La rejouer donnerait
        // le même verdict, donc elle est journalisée comme une donnée et c'est le code workflow
        // qui compacte puis redemande.
        if (ContextOverflow::detected($data)) {
            return $data;
        }

        if (method_exists($response, 'getStatusCode') && ($code = $response->getStatusCode()) >= 400) {
            throw new \RuntimeException(\sprintf('Le fournisseur a répondu %d : %s', $code, $response->getContent(false)));
        }

        return $data;
    }
}
