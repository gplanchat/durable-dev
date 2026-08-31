<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use App\Ai\Guard\AgentMode;
use App\Ai\Guard\PendingApproval;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;

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
        private readonly WorkflowMetadataStore $metadataStore,
    ) {
    }

    /**
     * Le payload d'une activité n'a pas la même profondeur selon le backend : en mémoire l'événement
     * porte les arguments de l'activité, sur Temporal il porte l'enveloppe `ActivityMessage` qui les
     * contient. On descend jusqu'à la couche qui porte la clé attendue plutôt que de coder une
     * profondeur — c'est la seule asymétrie que cette projection ait rencontrée entre les deux.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function descendTo(array $payload, string $key): array
    {
        while (!\array_key_exists($key, $payload) && \is_array($payload['payload'] ?? null)) {
            $payload = $payload['payload'];
        }

        return $payload;
    }

    public function forExecution(string $executionId): Transcript
    {
        $messages = [];
        $steps = [];
        $results = [];
        $lastModelCallId = null;
        $finished = false;
        $executed = [];
        $decided = [];
        $signalledMode = null;
        // La charge de démarrage n'est pas au même endroit selon le backend : sur Temporal natif
        // elle ouvre le journal (ExecutionStarted), sur DBAL un run dispatché n'écrit que ses
        // événements d'exécution et la charge reste dans le store de métadonnées. On lit les deux.
        $started = $this->metadataStore->get($executionId)['payload'] ?? [];

        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof ActivityScheduled) {
                $payload = $event->payload();

                if ('ai_model_invoke' === $event->activityName()) {
                    $messages = self::descendTo($payload, 'messages')['messages'] ?? $messages;
                    $lastModelCallId = $event->activityId();

                    continue;
                }

                if ('ai_tool_call' === $event->activityName()) {
                    $call = self::descendTo($payload, 'arguments');
                    $executed[(string) ($call['callId'] ?? '')] = true;
                    $steps[$event->activityId()] = new ToolStep(
                        (string) ($call['name'] ?? '?'),
                        (array) ($call['arguments'] ?? []),
                        null,
                    );
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
                    $signalledMode = AgentMode::tryFrom((string) ($signal['mode'] ?? '')) ?? $signalledMode;
                }

                continue;
            }

            if ($event instanceof ExecutionStarted) {
                $started = [] !== $started ? $started : $event->payload();

                continue;
            }

            if ($event instanceof ExecutionCompleted) {
                $finished = true;
            }
        }

        // Le dernier `set_mode` l'emporte sur le mode de démarrage : il lui est postérieur.
        $mode = $signalledMode ?? AgentMode::tryFrom((string) ($started['mode'] ?? '')) ?? AgentMode::Standard;
        $approvalTimeout = Duration::fromWireValue($started['approvalTimeoutSeconds'] ?? null);

        foreach ($steps as $activityId => $step) {
            $result = $results[$activityId] ?? null;
            $steps[$activityId] = $step->withResult(\is_string($result) ? $result : null);
        }

        // En attente d'accord : le modèle a demandé l'outil, la garde a suspendu, et ni l'activité
        // ni une décision ne sont au journal.
        $pending = [];
        foreach ($results[$lastModelCallId]['choices'][0]['message']['tool_calls'] ?? [] as $call) {
            $callId = (string) ($call['id'] ?? '');
            if (isset($executed[$callId]) || isset($decided[$callId])) {
                continue;
            }

            $ref = ToolCallRef::fromWire($call);
            $pending[] = new PendingApproval($ref->callId, $ref->tool, $ref->arguments, 'En attente de ta décision.');
        }

        $answer = $results[$lastModelCallId]['choices'][0]['message']['content'] ?? null;

        $thread = array_values(array_filter(
            array_map(TranscriptMessage::fromWire(...), $messages),
            static fn (TranscriptMessage $message): bool => !$message->isSystem(),
        ));

        if (\is_string($answer) && '' !== $answer) {
            $thread[] = TranscriptMessage::assistant($answer);
        }

        return new Transcript(
            $thread,
            array_values($steps),
            $pending,
            $mode,
            $approvalTimeout,
            // Un appel modèle planifié sans résultat, ou une réponse qui demande encore des outils :
            // l'agent travaille toujours.
            !$finished && [] === $pending && (
                null === $lastModelCallId
                || !\array_key_exists($lastModelCallId, $results)
                || !\is_string($answer)
            ),
            $finished,
        );
    }
}
