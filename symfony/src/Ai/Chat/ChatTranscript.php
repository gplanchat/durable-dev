<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use App\Ai\Guard\AgentMode;
use App\Ai\Guard\PendingApproval;
use App\Ai\Question\AskUserQuestion;
use App\Ai\Question\PendingQuestion;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
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

    /**
     * Quand l'échéance tranchera à la place de l'humain.
     *
     * `TimerScheduled::scheduledAt()` ne dit pas la même chose selon le backend : le cœur y met
     * l'instant de tir, le pont Temporal l'instant de départ — son convertisseur construit
     * `new TimerScheduled($id, $timerId, $ts)` avec l'horodatage de l'événement et laisse tomber
     * `startToFireTimeout`. Tant que l'attente dure, le tir est forcément à venir : c'est ce qui
     * permet de trancher entre les deux lectures sans deviner le backend.
     *
     * @param array<string, float> $deadlines minuteurs encore en vol
     */
    private static function expiryOf(array $deadlines, ?Duration $timeout): ?float
    {
        if ([] === $deadlines) {
            return null;
        }

        $scheduled = (float) end($deadlines);

        return $scheduled >= microtime(true) || null === $timeout
            ? $scheduled
            : $scheduled + $timeout->toSeconds();
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
        $answered = [];
        $signalledMode = null;
        $messagesSignalled = 0;
        $deadlines = [];
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

            if ($event instanceof TimerScheduled) {
                // Pas de filtre sur le résumé : il ne survit pas à l'aller-retour Temporal, où
                // l'événement revient sans lui. Un agent ne planifie de minuteur qu'ici, donc le
                // dernier minuteur encore en vol est l'échéance de l'attente en cours.
                $deadlines[$event->timerId()] = $event->scheduledAt();

                continue;
            }

            if ($event instanceof TimerCompleted || $event instanceof TimerCancelled) {
                unset($deadlines[$event->timerId()]);

                continue;
            }

            if ($event instanceof ActivityCompleted) {
                $results[$event->activityId()] = $event->result();

                continue;
            }

            if ($event instanceof WorkflowSignalReceived) {
                $signal = $event->signalPayload();
                if ('user_message' === $event->signalName()) {
                    ++$messagesSignalled;
                } elseif ('tool_decision' === $event->signalName()) {
                    $decided[(string) ($signal['callId'] ?? '')] = true;
                } elseif ('question_answered' === $event->signalName()) {
                    $answered[(string) ($signal['callId'] ?? '')] = true;
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
        $humanTimeout = Duration::fromWireValue($started['humanTimeoutSeconds'] ?? null);

        foreach ($steps as $activityId => $step) {
            $result = $results[$activityId] ?? null;
            $steps[$activityId] = $step->withResult(\is_string($result) ? $result : null);
        }

        // Deux attentes humaines, lues au même endroit : le modèle a demandé un outil, et ni
        // l'activité ni la réponse ne sont au journal. La garde retient l'un, le guichet l'autre.
        $expiresAt = self::expiryOf($deadlines, $humanTimeout);
        $pending = [];
        $questions = [];
        foreach ($results[$lastModelCallId]['choices'][0]['message']['tool_calls'] ?? [] as $call) {
            $callId = (string) ($call['id'] ?? '');
            if (isset($executed[$callId]) || isset($decided[$callId]) || isset($answered[$callId])) {
                continue;
            }

            $ref = ToolCallRef::fromWire($call);

            if (AskUserQuestion::TOOL === $ref->tool) {
                $questions[] = PendingQuestion::fromArguments($ref->callId, $ref->arguments, $expiresAt);

                continue;
            }

            $pending[] = new PendingApproval(
                $ref->callId,
                $ref->tool,
                $ref->arguments,
                'En attente de ta décision.',
                $expiresAt,
            );
        }

        $answer = $results[$lastModelCallId]['choices'][0]['message']['content'] ?? null;

        // Un message signalé que le dernier appel modèle ne contient pas encore : le tour a commencé
        // mais le journal n'en porte pas encore la trace. Sans ça l'agent paraît inactif entre la
        // soumission et la planification de l'activité.
        $messagesSeenByModel = \count(array_filter(
            $messages,
            static fn (array $message): bool => 'user' === ($message['role'] ?? null),
        ));

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
            $questions,
            $mode,
            $humanTimeout,
            // Trois façons d'avoir un tour en cours : un message reçu que le modèle n'a pas encore
            // vu, un appel modèle planifié sans résultat, ou une réponse qui demande encore des
            // outils. Aucun appel modèle du tout n'est *pas* un tour en cours : c'est l'état de
            // départ, où le workflow est suspendu sur son premier signal.
            !$finished && [] === $pending && [] === $questions && (
                $messagesSignalled > $messagesSeenByModel
                || (null !== $lastModelCallId && !\array_key_exists($lastModelCallId, $results))
                || (null !== $lastModelCallId && !\is_string($answer))
            ),
            $finished,
        );
    }
}
