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
final class AiChatController extends AbstractController
{
    /**
     * Échéance d'une demande de validation, globale à l'agent. Deux minutes pour que la démo soit
     * observable ; en production c'est l'ordre de grandeur du délai humain acceptable qui décide,
     * pas celui d'une requête HTTP.
     */
    private const APPROVAL_TIMEOUT_SECONDS = 120.0;

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

    #[Route('/durable/chat', name: 'durable_chat_start', methods: ['GET'])]
    public function start(): Response
    {
        $executionId = (string) Uuid::v4();
        $this->workflowRunner->dispatchWorkflowRun(
            DurableAgentWorkflow::class,
            [
                'tools' => ToolDefinition::listToWire(self::tools()),
                'mode' => AgentMode::Standard->value,
                'approvalTimeoutSeconds' => self::APPROVAL_TIMEOUT_SECONDS,
            ],
            $executionId,
        );

        return $this->redirectToRoute('durable_chat_show', ['executionId' => $executionId]);
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

    #[Route('/durable/chat/{executionId}/transcript', name: 'durable_chat_transcript', methods: ['GET'])]
    public function transcriptJson(string $executionId): JsonResponse
    {
        return new JsonResponse($this->transcript->forExecution($executionId));
    }
}
