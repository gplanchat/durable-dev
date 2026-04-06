<?php

declare(strict_types=1);

namespace App\Controller;

use App\Durable\DurableSampleWorkflowRunner;
use App\Samples\SampleWorkflowCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * UI listing ported temporalio/samples-php scenarios and starting a run via {@see DurableSampleWorkflowRunner}.
 */
final class SamplesWorkflowController extends AbstractController
{
    public function __construct(
        private readonly DurableSampleWorkflowRunner $workflowRunner,
    ) {
    }

    #[Route('/', name: 'durable_home', methods: ['GET'])]
    #[Route('/durable/samples', name: 'durable_samples_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('samples/index.html.twig', [
            'scenarios' => SampleWorkflowCatalog::scenarios(),
        ]);
    }

    #[Route('/durable/samples/run/{id}', name: 'durable_samples_run', methods: ['GET'])]
    public function run(string $id, Request $request): Response
    {
        $scenario = SampleWorkflowCatalog::findById($id);
        if (null === $scenario) {
            throw $this->createNotFoundException(\sprintf('Scénario inconnu: %s', $id));
        }

        $workflowType = $scenario['workflowType'];
        if (!$this->workflowRunner->hasWorkflow($workflowType)) {
            return $this->render('samples/error.html.twig', [
                'message' => \sprintf('Le type de workflow « %s » n’est pas enregistré.', $workflowType),
            ], new Response('', Response::HTTP_INTERNAL_SERVER_ERROR));
        }

        $payload = $scenario['defaultPayload'];
        $waitForResult = $request->query->has('wait')
            ? $request->query->getBoolean('wait')
            : true;

        try {
            if ($waitForResult) {
                $autoUpdate = $scenario['autoUpdate'] ?? null;
                $autoSignal = $scenario['autoSignal'] ?? null;
                if (\is_array($autoUpdate) && isset($autoUpdate['name'])) {
                    $outcome = $this->workflowRunner->runAndSettleWithAutoUpdate(
                        $workflowType,
                        $payload,
                        $autoUpdate['name'],
                        $autoUpdate['arguments'] ?? [],
                        $autoUpdate['result'] ?? null,
                    );
                } elseif (\is_array($autoSignal) && isset($autoSignal['name'])) {
                    $outcome = $this->workflowRunner->runAndSettleWithAutoSignal(
                        $workflowType,
                        $payload,
                        $autoSignal['name'],
                        $autoSignal['payload'] ?? [],
                    );
                } else {
                    $outcome = $this->workflowRunner->runAndSettle($workflowType, $payload);
                }

                return $this->render('samples/result.html.twig', [
                    'scenario' => $scenario,
                    'executionId' => $outcome['executionId'],
                    'result' => $outcome['result'],
                    'waitedForCompletion' => true,
                ]);
            }

            $executionId = $this->workflowRunner->dispatchWorkflowRun($workflowType, $payload);
        } catch (\Throwable $e) {
            return $this->render('samples/error.html.twig', [
                'message' => $e->getMessage(),
            ], new Response('', Response::HTTP_INTERNAL_SERVER_ERROR));
        }

        return $this->render('samples/result.html.twig', [
            'scenario' => $scenario,
            'executionId' => $executionId,
            'result' => null,
            'waitedForCompletion' => false,
        ]);
    }
}
