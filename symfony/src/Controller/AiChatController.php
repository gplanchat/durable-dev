<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Chat\ChatTranscript;
use App\Ai\Workflow\DurableChatWorkflow;
use App\Durable\DurableSampleWorkflowRunner;
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
    private const TOOLS = [
        'weather' => [
            'description' => 'Météo courante d’une ville.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['city' => ['type' => 'string']],
                'required' => ['city'],
            ],
        ],
    ];

    public function __construct(
        private readonly DurableSampleWorkflowRunner $workflowRunner,
        private readonly MessageBusInterface $messageBus,
        private readonly ChatTranscript $transcript,
    ) {
    }

    #[Route('/durable/chat', name: 'durable_chat_start', methods: ['GET'])]
    public function start(): Response
    {
        $executionId = (string) Uuid::v4();
        $this->workflowRunner->dispatchWorkflowRun(
            DurableChatWorkflow::class,
            ['tools' => self::TOOLS],
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
        $this->messageBus->dispatch(new DeliverWorkflowSignalMessage($executionId, 'user_message', ['text' => $text]));

        return new JsonResponse(null, Response::HTTP_ACCEPTED);
    }

    #[Route('/durable/chat/{executionId}/close', name: 'durable_chat_close', methods: ['POST'])]
    public function close(string $executionId): JsonResponse
    {
        $this->messageBus->dispatch(new DeliverWorkflowSignalMessage($executionId, 'close', []));

        return new JsonResponse(null, Response::HTTP_ACCEPTED);
    }

    #[Route('/durable/chat/{executionId}/transcript', name: 'durable_chat_transcript', methods: ['GET'])]
    public function transcriptJson(string $executionId): JsonResponse
    {
        return new JsonResponse($this->transcript->forExecution($executionId));
    }
}
