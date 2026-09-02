<?php

declare(strict_types=1);

namespace App\Ai\Context;

/**
 * Reconnaître un dépassement de fenêtre dans ce que le fournisseur a répondu.
 *
 * Ce n'est pas une panne de transport, c'est une **réponse** : « ta charge est trop grosse ».
 * La rejouer telle quelle donnera le même verdict, donc la politique de retentative de Durable
 * n'a rien à faire ici — c'est au code workflow de changer la charge et de redemander. D'où le
 * traitement à part de {@see \App\Ai\Activity\ModelInvocationActivityHandler}, qui relève sur
 * toutes les autres erreurs mais journalise celle-ci comme une donnée.
 *
 * Les codes viennent du pont Mistral, qui les reconnaît de la même façon pour lever son
 * `ExceedContextSizeException`.
 *
 * @see \Symfony\AI\Platform\Bridge\Mistral\Llm\ResultConverter
 */
final class ContextOverflow
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data corps de la réponse du fournisseur
     */
    public static function detected(array $data): bool
    {
        $code = $data['error']['code'] ?? $data['code'] ?? null;
        if ('context_length_exceeded' === $code) {
            return true;
        }

        $message = (string) ($data['error']['message'] ?? $data['message'] ?? '');

        return '' !== $message
            && (str_contains($message, 'maximum context length') || str_contains($message, 'context_length_exceeded'));
    }
}
