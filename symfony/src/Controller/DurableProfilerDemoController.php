<?php

declare(strict_types=1);

namespace App\Controller;

use App\Durable\DurableSampleWorkflowRunner;
use App\Durable\DurableSampleWorkflows;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Démo : une requête HTTP lance un workflow et remplit le panneau profiler « Durable »
 * (dispatch Messenger, runs workflow, activités) pour la même requête — en mode distribué,
 * le drain des transports se fait dans ce contrôleur.
 */
final class DurableProfilerDemoController extends AbstractController
{
    public function __construct(
        private readonly DurableSampleWorkflowRunner $workflowRunner,
    ) {
    }

    #[Route('/', name: 'durable_profiler_demo_home')]
    public function home(): Response
    {
        return $this->render('durable/profiler_demo_home.html.twig');
    }

    #[Route('/durable/profiler-demo', name: 'durable_profiler_demo_run', methods: ['GET'])]
    public function runDemo(Request $request): Response
    {
        // Pas de pause longue par défaut : un delay() distribué repose sur des messages différés ; le drain HTTP
        // doit alors attendre le temps réel (voir DurableMessengerDrain). Ajoutez ?pause=10 pour tester les timers.
        $pauseSeconds = 0.0;
        if ($request->query->has('pause')) {
            $rawPause = $request->query->get('pause');
            $pauseSeconds = \is_numeric($rawPause) ? max(0.0, (float) $rawPause) : 0.0;
        }
        $payload = [
            'first' => 'ProfilerA',
            'second' => 'ProfilerB',
            'pauseSeconds' => $pauseSeconds,
        ];

        // Par défaut : attendre la fin (drain Messenger) pour que l’event store et le profiler contiennent le journal
        // sur cette même requête. `?wait=0` reproduit l’envoi seul (fire-and-forget, workers séparés).
        $waitForResult = $request->query->has('wait')
            ? $request->query->getBoolean('wait')
            : true;
        $forcedExecutionId = $this->parseOptionalExecutionId($request);

        try {
            if ($waitForResult) {
                $outcome = $this->workflowRunner->runAndSettle(
                    DurableSampleWorkflows::PARALLEL_CHILD_ECHO,
                    $payload,
                    $forcedExecutionId,
                );

                return $this->render('durable/profiler_demo_result.html.twig', [
                    'workflowType' => DurableSampleWorkflows::PARALLEL_CHILD_ECHO,
                    'executionId' => $outcome['executionId'],
                    'payload' => $payload,
                    'result' => $outcome['result'],
                    'waitedForCompletion' => true,
                ]);
            }

            $executionId = $this->workflowRunner->dispatchWorkflowRun(
                DurableSampleWorkflows::PARALLEL_CHILD_ECHO,
                $payload,
                $forcedExecutionId,
            );
        } catch (\Throwable $e) {
            return $this->render('durable/profiler_demo_error.html.twig', [
                'message' => $e->getMessage(),
            ], new Response('', Response::HTTP_INTERNAL_SERVER_ERROR));
        }

        return $this->render('durable/profiler_demo_result.html.twig', [
            'workflowType' => DurableSampleWorkflows::PARALLEL_CHILD_ECHO,
            'executionId' => $executionId,
            'payload' => $payload,
            'result' => null,
            'waitedForCompletion' => false,
        ]);
    }

    /**
     * Aligné sur {@see \Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector} : même paramètre
     * `durable_execution` (un seul UUID utile pour forcer l’identifiant du run).
     */
    private function parseOptionalExecutionId(Request $request): ?string
    {
        $raw = $request->query->get('durable_execution');
        if (!\is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ('' === $raw) {
            return null;
        }
        $first = trim(explode(',', $raw)[0]);

        return '' === $first ? null : $first;
    }
}
