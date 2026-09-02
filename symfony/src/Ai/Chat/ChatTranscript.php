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
        $compactionCallId = null;
        $finished = false;
        $executed = [];
        $decided = [];
        $signalledMode = null;
        $messagesSignalled = 0;
        $deadlines = [];
        // La charge de démarrage n'est pas au même endroit selon le backend : sur Temporal natif
        // elle ouvre le journal (ExecutionStarted), sur DBAL un run dispatché n'écrit que ses
        // événements d'exécution et la charge reste dans le store de métadonnées. On lit les deux.
        //
        // L'événement passe devant, et ce n'est pas un détail de style : après un continue-as-new,
        // le store de métadonnées porte encore la charge du **premier** run — celle d'avant le
        // relais, dont le fil est vide. Seul l'événement du run courant dit ce que ce run a repris.
        $startedFromStore = $this->metadataStore->get($executionId)['payload'] ?? [];
        $started = [];

        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof ActivityScheduled) {
                $payload = $event->payload();

                if ('ai_model_invoke' === $event->activityName()) {
                    $messages = self::descendTo($payload, 'messages')['messages'] ?? $messages;
                    $lastModelCallId = $event->activityId();

                    continue;
                }

                // La compaction est un appel modèle comme un autre, sous un nom à part : sa charge
                // est la conversation qu'on remplace, pas celle du tour en cours.
                if ('ai_model_compact' === $event->activityName()) {
                    $compactionCallId = $event->activityId();

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
                } elseif ('set_mode' === $event->signalName()) {
                    $signalledMode = AgentMode::tryFrom((string) ($signal['mode'] ?? '')) ?? $signalledMode;
                }

                continue;
            }

            if ($event instanceof ExecutionStarted) {
                $started = $event->payload();

                continue;
            }

            if ($event instanceof ExecutionCompleted) {
                $finished = true;
            }
        }

        $started = [] !== $started ? $started : $startedFromStore;

        // Un run repris — reprise après clôture ou continue-as-new — porte son fil d'origine dans
        // sa charge de démarrage. Tant qu'aucun appel modèle ne l'a réémis, c'est la seule trace
        // qu'en ait le journal ; sans ça la conversation paraît s'être vidée.
        //
        // Sauf s'il l'a compacté : c'est alors le résumé qui fait foi, dès avant le premier tour.
        // L'afficher plus tôt n'aurait pas seulement l'air faux — le fil complet compterait des
        // messages que le modèle ne verra jamais, et fausserait le statut de l'agent.
        $digest = null !== $compactionCallId
            ? ($results[$compactionCallId]['choices'][0]['message']['content'] ?? null)
            : null;
        $carried = \is_string($digest) && '' !== trim($digest)
            ? [TranscriptMessage::compaction(trim($digest))->toWire()]
            : $started['history'] ?? [];
        if ([] === $messages) {
            $messages = $carried;
        }

        // Le fil repris entre dans le sac dès le premier appel modèle. Sans le compter du côté des
        // messages reçus, `messagesSignalled` (ce run seul) et `messagesSeenByModel` (le sac entier)
        // cessent de parler de la même chose, et l'agent paraît au repos pendant qu'il travaille.
        $messagesSignalled += \count(array_filter(
            $carried,
            static fn (array $message): bool => 'user' === ($message['role'] ?? null),
        ));

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
            $pending[] = new PendingApproval(
                $ref->callId,
                $ref->tool,
                $ref->arguments,
                'En attente de ta décision.',
                self::expiryOf($deadlines, $approvalTimeout),
            );
        }

        $answer = $results[$lastModelCallId]['choices'][0]['message']['content'] ?? null;
        // Le raisonnement du dernier tour n'est nulle part ailleurs : les tours précédents ont le
        // leur dans la charge du tour d'après (le normaliseur l'y remet), mais le dernier n'a pas
        // de tour d'après. Il se lit dans le résultat, à côté de la réponse.
        $lastReasoning = trim((string) ($results[$lastModelCallId]['choices'][0]['message']['reasoning_content'] ?? ''));

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
            $thread[] = TranscriptMessage::assistant($answer, '' === $lastReasoning ? null : $lastReasoning);
        }

        return new Transcript(
            $thread,
            array_values($steps),
            $pending,
            $mode,
            $approvalTimeout,
            // Quatre façons d'avoir un tour en cours : une compaction en vol, un message reçu que
            // le modèle n'a pas encore vu, un appel modèle planifié sans résultat, ou une réponse
            // qui demande encore des outils. Aucun appel modèle du tout n'est *pas* un tour en
            // cours : c'est l'état de départ, où le workflow est suspendu sur son premier signal.
            !$finished && [] === $pending && (
                (null !== $compactionCallId && !\array_key_exists($compactionCallId, $results))
                || $messagesSignalled > $messagesSeenByModel
                || (null !== $lastModelCallId && !\array_key_exists($lastModelCallId, $results))
                || (null !== $lastModelCallId && !\is_string($answer))
            ),
            $finished,
        );
    }
}
