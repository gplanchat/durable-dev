<?php

declare(strict_types=1);

namespace App\Ai\Platform;

use Symfony\AI\Platform\Bridge\Mistral\Llm\ModelClient as MistralModelClient;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Qui répond réellement au modèle, côté activité.
 *
 * Sans clé, la démo reste utilisable : le client scripté rend des réponses déterministes et tout
 * le reste — journal, garde, guichet, échéances — se comporte exactement pareil. Avec une clé,
 * c'est Mistral qui parle, et rien d'autre ne change dans le code du workflow.
 *
 * ponytail: un `if` plutôt qu'un compilateur de conteneur. Le jour où il y a trois fournisseurs,
 * ce sera un tag et un locator — pas avant.
 */
final class ModelClientFactory
{
    private function __construct()
    {
    }

    public static function create(
        #[\SensitiveParameter] string $apiKey,
        HttpClientInterface $httpClient,
        ScriptedChatModelClient $scripted,
    ): ModelClientInterface {
        return '' === trim($apiKey) ? $scripted : new MistralModelClient($httpClient, $apiKey);
    }
}
