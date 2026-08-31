<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Le fil de la conversation est une **projection du journal**, pas un état stocké à côté.
 *
 * Chaque `ai_model_invoke` porte en entrée la conversation complète du tour ; le dernier
 * `ActivityScheduled` de ce nom est donc l'instantané le plus riche, et sa complétion porte la
 * réponse. Aucune query workflow n'est nécessaire : DUR037/DUR043, une projection sur les
 * événements.
 */
final class ChatTranscript
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
    ) {
    }

    /**
     * @return array{messages: list<array<string, mixed>>, steps: list<array{tool: string, arguments: array<string, mixed>, result: string|null}>, working: bool, finished: bool}
     */
    public function forExecution(string $executionId): array
    {
        $messages = [];
        $steps = [];
        $results = [];
        $lastModelCallId = null;
        $finished = false;

        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof ActivityScheduled) {
                $payload = $event->payload()['payload'] ?? [];

                if ('ai_model_invoke' === $event->activityName()) {
                    $messages = $payload['payload']['messages'] ?? $messages;
                    $lastModelCallId = $event->activityId();

                    continue;
                }

                if ('ai_tool_call' === $event->activityName()) {
                    $steps[] = [
                        'id' => $event->activityId(),
                        'tool' => (string) ($payload['name'] ?? '?'),
                        'arguments' => (array) ($payload['arguments'] ?? []),
                        'result' => null,
                    ];
                }

                continue;
            }

            if ($event instanceof ActivityCompleted) {
                $results[$event->activityId()] = $event->result();

                continue;
            }

            if ($event instanceof ExecutionCompleted) {
                $finished = true;
            }
        }

        foreach ($steps as $index => $step) {
            $steps[$index]['result'] = \is_string($results[$step['id']] ?? null) ? $results[$step['id']] : null;
            unset($steps[$index]['id']);
        }

        $answer = $results[$lastModelCallId]['choices'][0]['message']['content'] ?? null;
        if (\is_string($answer) && '' !== $answer) {
            $messages[] = ['role' => 'assistant', 'content' => $answer];
        }

        return [
            'messages' => array_values(array_filter(
                $messages,
                static fn (array $message): bool => 'system' !== ($message['role'] ?? null),
            )),
            'steps' => array_values($steps),
            // Un appel modèle planifié sans résultat, ou une réponse qui demande encore des outils :
            // l'agent travaille toujours.
            'working' => !$finished && (
                null === $lastModelCallId
                || !\array_key_exists($lastModelCallId, $results)
                || !\is_string($answer)
            ),
            'finished' => $finished,
        ];
    }
}
