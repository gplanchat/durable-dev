<?php

declare(strict_types=1);

namespace App\Ai\Platform;

use App\Ai\Chat\ToolCallRef;
use App\Ai\Chat\TranscriptMessage;

/**
 * La réponse d'un fournisseur « chat completions », lue une fois pour toutes.
 *
 * `$data['choices'][0]['message'][…]` s'écrivait à la main partout où on en avait besoin — le
 * workflow pour la compaction, la projection trois fois, le modèle scripté pour la fabriquer.
 * Autant d'occasions de se tromper de niveau, et un changement de fournisseur à faire autant de
 * fois. Ici la forme est décrite une fois, dans les deux sens.
 *
 * Fabrique de frontière comme {@see \App\Ai\Context\Conversation} : `fromWire()` pour ce qui sort
 * du journal, `toWire()` pour ce que le modèle scripté y écrit. Le fil reste le fil, il ne
 * traverse plus le code.
 *
 * Ce qui n'y passe **pas** : l'enveloppe d'erreur. {@see \App\Ai\Context\ContextOverflow} lit un
 * `error.code`, pas un `choice` — un dépassement de fenêtre n'est pas une complétion, et le faire
 * transiter par ce type reviendrait à en fabriquer une vide.
 */
final readonly class ChatCompletion
{
    /**
     * @param list<ToolCallRef> $toolCalls
     */
    private function __construct(
        public ?string $text,
        public ?string $reasoning,
        public array $toolCalls,
    ) {
    }

    /**
     * `null` quand le journal ne porte pas de réponse — et c'est une information, pas un défaut :
     * c'est elle qui distingue un tour en cours d'un tour fini.
     */
    public static function fromWire(mixed $result): ?self
    {
        $message = \is_array($result) ? $result['choices'][0]['message'] ?? null : null;
        if (!\is_array($message)) {
            return null;
        }

        $sidecar = $message['reasoning_content'] ?? null;
        [$text, $reasoning] = TranscriptMessage::splitContent(
            $message['content'] ?? null,
            \is_string($sidecar) ? $sidecar : null,
        );

        $calls = $message['tool_calls'] ?? null;

        return new self($text, $reasoning, array_map(
            ToolCallRef::fromWire(...),
            array_values(\is_array($calls) ? $calls : []),
        ));
    }

    public static function ofText(string $text, ?string $reasoning = null): self
    {
        return new self($text, $reasoning, []);
    }

    public static function ofToolCall(ToolCallRef $call): self
    {
        return new self(null, null, [$call]);
    }

    /**
     * La forme exacte qu'attend le convertisseur du pont — c'est du fil, il part tel quel.
     *
     * Le raisonnement s'écrit en morceaux `thinking`/`text` et non dans un `reasoning_content` à
     * côté : c'est la forme de Mistral, et `splitContent()` lit les deux ({@see TranscriptMessage}).
     * Sans raisonnement on garde la chaîne simple qu'attendent tous les autres modèles.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $message = ['content' => null === $this->reasoning ? $this->text : [
            ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => $this->reasoning]]],
            ['type' => 'text', 'text' => (string) $this->text],
        ]];

        if ([] !== $this->toolCalls) {
            $message['tool_calls'] = array_map(static fn (ToolCallRef $call): array => [
                'id' => $call->callId,
                'type' => 'function',
                'function' => [
                    'name' => $call->tool,
                    'arguments' => json_encode($call->arguments, \JSON_UNESCAPED_UNICODE),
                ],
            ], $this->toolCalls);
        }

        return ['choices' => [[
            'message' => $message,
            'finish_reason' => [] === $this->toolCalls ? 'stop' : 'tool_calls',
        ]]];
    }
}
