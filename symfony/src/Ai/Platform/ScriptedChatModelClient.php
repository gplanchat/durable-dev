<?php

declare(strict_types=1);

namespace App\Ai\Platform;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;

/**
 * Répond comme un fournisseur « chat completions », sans réseau ni clé d'API.
 *
 * La démo n'a pas besoin d'un vrai modèle pour montrer ce qu'elle montre : le journal, les
 * activités, et le fait que la boucle se rejoue. Un vrai fournisseur se branche en remplaçant ce
 * service par le `ModelClientInterface` d'un bridge `symfony/ai-*-platform`.
 *
 * La réponse est fonction de la conversation reçue — donc déterministe, donc rejouable.
 */
final class ScriptedChatModelClient implements ModelClientInterface
{
    private const CITIES = ['Paris', 'Lyon'];

    public function supports(Model $model): bool
    {
        return true;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        $answered = \count(array_filter(
            $payload['messages'] ?? [],
            static fn (array $message): bool => 'tool' === ($message['role'] ?? null),
        ));

        if ($answered < \count(self::CITIES)) {
            return new InMemoryRawResult($this->toolCall($answered, self::CITIES[$answered]));
        }

        return new InMemoryRawResult($this->text(\sprintf(
            'Relevé pour %s — demandé via %d appels d\'outil.',
            implode(' et ', self::CITIES),
            $answered,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function toolCall(int $index, string $city): array
    {
        return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
            'id' => \sprintf('call_%d', $index + 1),
            'type' => 'function',
            'function' => ['name' => 'weather', 'arguments' => json_encode(['city' => $city])],
        ]]], 'finish_reason' => 'tool_calls']]];
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $text): array
    {
        return ['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']]];
    }
}
