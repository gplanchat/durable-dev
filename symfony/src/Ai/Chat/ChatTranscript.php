<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
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
     * @return array{messages: list<array<string, mixed>>, steps: list<array{tool: string, arguments: array<string, mixed>, result: string|null}>, pending: list<array{callId: string, tool: string, arguments: array<string, mixed>}>, mode: string, working: bool, finished: bool}
     */
    public function forExecution(string $executionId): array
    {
        $messages = [];
        $steps = [];
        $results = [];
        $lastModelCallId = null;
        $finished = false;
        $executed = [];
        $decided = [];
        $mode = 'standard';

        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof ActivityScheduled) {
                $payload = $event->payload()['payload'] ?? [];

                if ('ai_model_invoke' === $event->activityName()) {
                    $messages = $payload['payload']['messages'] ?? $messages;
                    $lastModelCallId = $event->activityId();

                    continue;
                }

                if ('ai_tool_call' === $event->activityName()) {
                    $executed[(string) ($payload['callId'] ?? '')] = true;
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

            if ($event instanceof WorkflowSignalReceived) {
                $signal = $event->signalPayload();
                if ('tool_decision' === $event->signalName()) {
                    $decided[(string) ($signal['callId'] ?? '')] = true;
                } elseif ('set_mode' === $event->signalName()) {
                    $mode = (string) ($signal['mode'] ?? $mode);
                }

                continue;
            }

            if ($event instanceof ExecutionStarted) {
                $mode = (string) ($event->payload()['mode'] ?? $mode);

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

        // En attente d'accord : le modèle a demandé l'outil, la garde a suspendu, et ni l'activité
        // ni une décision ne sont au journal.
        $pending = [];
        foreach ($results[$lastModelCallId]['choices'][0]['message']['tool_calls'] ?? [] as $call) {
            $callId = (string) ($call['id'] ?? '');
            if (isset($executed[$callId]) || isset($decided[$callId])) {
                continue;
            }

            $pending[] = [
                'callId' => $callId,
                'tool' => (string) ($call['function']['name'] ?? '?'),
                'arguments' => json_decode((string) ($call['function']['arguments'] ?? '{}'), true) ?: [],
            ];
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
            'pending' => $pending,
            'mode' => $mode,
            // Un appel modèle planifié sans résultat, ou une réponse qui demande encore des outils :
            // l'agent travaille toujours.
            'working' => !$finished && [] === $pending && (
                null === $lastModelCallId
                || !\array_key_exists($lastModelCallId, $results)
                || !\is_string($answer)
            ),
            'finished' => $finished,
        ];
    }
}
