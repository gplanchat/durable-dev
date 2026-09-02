<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Chat\ChatTranscript;
use App\Ai\Guard\AgentMode;
use App\Ai\Guard\ToolEffect;
use App\Ai\Tool\ToolDefinition;
use App\Ai\Workflow\DurableAgentWorkflow;
use App\Durable\DurableSampleWorkflowRunner;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Démo de chat sur un agent durable : une conversation = une exécution de workflow, chaque message
 * de l'humain = un signal. Entre deux messages le workflow est suspendu, pas en attente.
 */
#[Route(host: '%app.host.agent%')]
final class AiChatController extends AbstractController
{
    /**
     * Échéance de toute attente humaine — validation d'un outil comme réponse à une question.
     *
     * Un quart d'heure : le délai doit être celui d'un humain qui lit, réfléchit et change de
     * fenêtre — pas celui d'une requête HTTP. À 120 s la carte disparaissait sous les yeux de qui
     * la lisait, et l'agent répondait « refusé faute de validation » sans que personne n'ait rien
     * refusé.
     */
    private const HUMAN_TIMEOUT_SECONDS = 900.0;

    /**
     * Silence au bout duquel l'exécution se termine d'elle-même.
     *
     * Une conversation abandonnée n'a pas de raison de rester ouverte : sur Temporal chaque
     * exécution ouverte a un coût, et une heure sans un mot est une conversation finie. Le fil
     * n'est pas perdu pour autant — il est au journal, et {@see resume()} le reprend.
     */
    private const IDLE_TIMEOUT_SECONDS = 3600.0;

    /**
     * Tours au bout desquels le run passe la main à un run neuf.
     *
     * Ce n'est pas le journal qui borne : à une dizaine d'événements par tour, Temporal en tolère
     * cent fois plus. C'est le **coût par tour** — chaque tour renvoie tout le sac au modèle, donc
     * la dépense d'une conversation croît avec le carré de sa longueur. Quarante tours est le point
     * où la reprise coûte moins que la continuation.
     */
    private const ROLLOVER_AFTER_TURNS = 40;

    /**
     * Le budget de fenêtre par défaut d'une conversation. `?contexte=N` le remplace, sous les
     * bornes de {@see start()} — c'est ce qui rend la compaction observable à la main.
     */
    private const DEFAULT_CONTEXT_TOKENS = 24_000;

    /**
     * Le catalogue d'outils de la démo. `effect` est ce que lit la garde : c'est lui, et pas le nom
     * de l'outil, que le mode consulte — et le déclarer en {@see ToolEffect} fait lever une faute de
     * frappe ici plutôt que de la traduire silencieusement en « externe ».
     *
     * @return list<ToolDefinition>
     */
    private static function tools(): array
    {
        return [
            new ToolDefinition('weather', 'Météo courante d’une ville.', ToolEffect::Read, [
                'type' => 'object',
                'properties' => ['city' => ['type' => 'string']],
                'required' => ['city'],
            ]),
            new ToolDefinition('save_note', 'Enregistre une note dans le dossier courant.', ToolEffect::Write, [
                'type' => 'object',
                'properties' => ['text' => ['type' => 'string']],
                'required' => ['text'],
            ]),
            new ToolDefinition('send_email', 'Envoie un courriel. Effet externe : rien ne le rattrape.', ToolEffect::External, [
                'type' => 'object',
                'properties' => ['to' => ['type' => 'string'], 'body' => ['type' => 'string']],
                'required' => ['to', 'body'],
            ]),
        ];
    }

    public function __construct(
        private readonly DurableSampleWorkflowRunner $workflowRunner,
        private readonly MessageBusInterface $messageBus,
        private readonly ChatTranscript $transcript,
        private readonly ?WorkflowClientInterface $workflowClient = null,
    ) {
    }

    /**
     * Un signal n'a pas le même chemin selon qui détient le journal.
     *
     * Sur Temporal natif le cluster **est** le journal : `TemporalReadThroughEventStore::append()`
     * n'écrit que dans le cache local de la requête, donc un `WorkflowSignalReceived` posé là
     * disparaît avec le processus. Le signal doit partir au cluster.
     *
     * Sur DBAL (DUR030) il n'y a pas de cluster : le message Messenger est la bonne porte, et
     * `DeliverWorkflowSignalHandler` écrit dans un journal SQL que tout le monde relit.
     *
     * @param array<string, mixed> $payload
     */
    private function signal(string $executionId, string $signalName, array $payload): void
    {
        if (null !== $this->workflowClient) {
            $this->workflowClient->signal($this->workflowClient->workflowId($executionId), $signalName, $payload);

            return;
        }

        $this->messageBus->dispatch(new DeliverWorkflowSignalMessage($executionId, $signalName, $payload));
    }

    /**
     * @param list<array{role: string, content: string}> $history        le fil repris d'une exécution close
     * @param bool                                       $compactHistory remplacer ce fil par un résumé avant le premier tour
     * @param int                                        $contextTokens  budget de fenêtre de l'agent
     */
    private function startAgent(array $history = [], bool $compactHistory = false, int $contextTokens = self::DEFAULT_CONTEXT_TOKENS): string
    {
        $executionId = (string) Uuid::v4();
        $this->workflowRunner->dispatchWorkflowRun(
            DurableAgentWorkflow::class,
            [
                'tools' => ToolDefinition::listToWire(self::tools()),
                'mode' => AgentMode::Standard->value,
                'humanTimeoutSeconds' => self::HUMAN_TIMEOUT_SECONDS,
                'contextTokens' => $contextTokens,
                'idleTimeoutSeconds' => self::IDLE_TIMEOUT_SECONDS,
                'rolloverAfterTurns' => self::ROLLOVER_AFTER_TURNS,
                'compactHistory' => $compactHistory,
                'history' => $history,
            ],
            $executionId,
        );

        return $executionId;
    }

    /**
     * La racine du domaine de l'agent. Les samples ont la leur, sur le leur : deux `/`, deux hôtes,
     * et c'est l'hôte qui tranche.
     */
    #[Route('/', name: 'durable_agent_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->redirectToRoute('durable_chat_start');
    }

    /**
     * `?contexte=300` ouvre une conversation à budget minuscule : la compaction se déclenche alors
     * en deux ou trois messages au lieu de plusieurs centaines, et devient observable à la main.
     */
    #[Route('/durable/chat', name: 'durable_chat_start', methods: ['GET'])]
    public function start(Request $request): Response
    {
        return $this->redirectToRoute('durable_chat_show', ['executionId' => $this->startAgent(
            contextTokens: max(200, min(200_000, $request->query->getInt('contexte', self::DEFAULT_CONTEXT_TOKENS))),
        )]);
    }

    /**
     * Reprendre une conversation dont l'exécution est close — inactivité, `close`, ou tours épuisés.
     *
     * Une exécution close ne se rouvre pas : on en ouvre une neuve, et le fil qu'elle reprend sort
     * de la projection du journal de l'ancienne. Rien n'est stocké entre les deux.
     *
     * Et il repart **compacté** : une conversation qu'on reprend après une heure de silence est
     * froide, et la rejouer mot pour mot ferait payer au premier tour tout ce que le précédent
     * avait déjà coûté. Le résumé est un appel modèle du run repris, donc journalisé, donc payé
     * une fois même si la reprise redémarre.
     */
    #[Route('/durable/chat/{executionId}/resume', name: 'durable_chat_resume', methods: ['POST'])]
    public function resume(string $executionId): JsonResponse
    {
        $resumed = $this->startAgent($this->transcript->forExecution($executionId)->seed(), compactHistory: true);

        return new JsonResponse(
            ['url' => $this->generateUrl('durable_chat_show', ['executionId' => $resumed])],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/durable/chat/{executionId}', name: 'durable_chat_show', methods: ['GET'])]
    public function show(string $executionId): Response
    {
        return $this->render('ai/chat.html.twig', ['executionId' => $executionId]);
    }

    #[Route('/durable/chat/{executionId}/message', name: 'durable_chat_message', methods: ['POST'])]
    public function message(string $executionId, Request $request): JsonResponse
    {
        $text = trim((string) ($request->toArray()['text'] ?? ''));
        if ('' === $text) {
            return new JsonResponse(['error' => 'Message vide.'], Response::HTTP_BAD_REQUEST);
        }

        // Le workflow est suspendu sur sa condition ; le signal le réveille. Rien à attendre ici,
        // la page relit la projection.
        $this->signal($executionId, 'user_message', ['text' => $text]);

        return new JsonResponse(null, Response::HTTP_ACCEPTED);
    }

    /**
     * L'accord ou le refus d'un appel d'outil : un signal, donc journalisé, donc rejoué. Le workflow
     * peut avoir été suspendu là-dessus depuis des jours.
     */
    #[Route('/durable/chat/{executionId}/decision', name: 'durable_chat_decision', methods: ['POST'])]
    public function decision(string $executionId, Request $request): JsonResponse
    {
        $body = $request->toArray();
        $this->signal($executionId, 'tool_decision', [
            'callId' => (string) ($body['callId'] ?? ''),
            'approved' => (bool) ($body['approved'] ?? false),
        ]);

        return new JsonResponse(null, Response::HTTP_ACCEPTED);
    }

    /**
     * La réponse à une question posée par l'agent. Rien à valider ici : l'humain renseigne, il
     * n'autorise pas.
     */
    #[Route('/durable/chat/{executionId}/answer', name: 'durable_chat_answer', methods: ['POST'])]
    public function answer(string $executionId, Request $request): JsonResponse
    {
        $body = $request->toArray();
        $answers = \is_array($body['answers'] ?? null) ? $body['answers'] : [];

        $this->signal($executionId, 'question_answered', [
            'callId' => (string) ($body['callId'] ?? ''),
            'answers' => array_values(array_map(strval(...), $answers)),
        ]);

        return new JsonResponse(null, Response::HTTP_ACCEPTED);
    }

    /**
     * Lever une alerte : c'est le rôle qu'une supervision, un webhook ou un autre agent tiendrait
     * en production. La page l'imite pour que la veille soit démontrable.
     */
    #[Route('/durable/chat/{executionId}/alert', name: 'durable_chat_alert', methods: ['POST'])]
    public function alert(string $executionId, Request $request): JsonResponse
    {
        $body = $request->toArray();

        $this->signal($executionId, 'alerte', [
            'callId' => (string) ($body['callId'] ?? ''),
            'observation' => (string) ($body['observation'] ?? ''),
        ]);

        return new JsonResponse(null, Response::HTTP_ACCEPTED);
    }

    #[Route('/durable/chat/{executionId}/mode', name: 'durable_chat_mode', methods: ['POST'])]
    public function mode(string $executionId, Request $request): JsonResponse
    {
        $mode = AgentMode::tryFrom((string) ($request->toArray()['mode'] ?? ''));
        if (null === $mode) {
            return new JsonResponse(['error' => 'Mode inconnu.'], Response::HTTP_BAD_REQUEST);
        }

        $this->signal($executionId, 'set_mode', ['mode' => $mode->value]);

        return new JsonResponse(null, Response::HTTP_ACCEPTED);
    }

    #[Route('/durable/chat/{executionId}/close', name: 'durable_chat_close', methods: ['POST'])]
    public function close(string $executionId): JsonResponse
    {
        $this->signal($executionId, 'close', []);

        return new JsonResponse(null, Response::HTTP_ACCEPTED);
    }

    /**
     * Le fil, tel que le journal le raconte, avec un ETag.
     *
     * Le sondage demande toutes les secondes et la réponse est presque toujours la même : l'ETag
     * la rend gratuite à sérialiser et à transporter. **Il ne rend pas la lecture gratuite** —
     * {@see ChatTranscript::forExecution()} rejoue le flux d'événements à chaque appel, et ça, seul
     * un vrai canal poussé l'éviterait. Ce qui coûte ici, c'est la lecture, pas le JSON.
     */
    #[Route('/durable/chat/{executionId}/transcript', name: 'durable_chat_transcript', methods: ['GET'])]
    public function transcriptJson(string $executionId, Request $request): JsonResponse
    {
        $response = new JsonResponse($this->transcript->forExecution($executionId));
        $response->setEtag(hash('xxh128', (string) $response->getContent()));
        $response->setPrivate();
        $response->isNotModified($request);

        return $response;
    }
}
