<?php

declare(strict_types=1);

namespace App\Ai\Durable;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/**
 * Convertit la réponse brute « chat completions » journalisée en résultat Symfony AI.
 *
 * ponytail: le convertisseur du vrai bridge (`symfony/ai-open-ai-platform`, `-mistral-platform`, …)
 * remplace celui-ci tel quel — c'est le même contrat, sur le même tableau brut. Écrit à la main ici
 * pour que le prototype tourne sans clé d'API ni dépendance de bridge.
 */
final class ChatCompletionResultConverter implements ResultConverterInterface
{
    public function supports(Model $model): bool
    {
        return true;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        $message = $result->getData()['choices'][0]['message'] ?? [];

        $parts = [];

        // Le bloc de raisonnement d'abord : `Message::toContent()` le rendra à un `Thinking`, que
        // `AssistantMessageNormalizer` remettra en `reasoning_content` au tour suivant. Le laisser
        // tomber ici ampute le tour d'après **sans rien lever** — et chez un fournisseur qui vérifie
        // ses blocs au renvoi, l'appel échoue.
        if ('' !== ($reasoning = trim((string) ($message['reasoning_content'] ?? $message['reasoning'] ?? '')))) {
            $parts[] = new ThinkingResult($reasoning);
        }

        if ([] !== ($message['tool_calls'] ?? [])) {
            $parts[] = new ToolCallResult(array_map(
                static fn (array $call): ToolCall => new ToolCall(
                    $call['id'],
                    $call['function']['name'],
                    json_decode($call['function']['arguments'] ?? '{}', true, flags: \JSON_THROW_ON_ERROR),
                ),
                $message['tool_calls'],
            ));
        } else {
            $parts[] = new TextResult((string) ($message['content'] ?? ''));
        }

        return 1 === \count($parts) ? $parts[0] : new MultiPartResult($parts);
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
    }
}
