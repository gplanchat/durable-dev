<?php

declare(strict_types=1);

namespace App\Controller;

use App\Durable\DurableSampleWorkflowRunner;
use App\Samples\SampleWorkflowCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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

    /**
     * A POST, refused when the browser says it comes from another site: starting a run is a write,
     * and a link, a prefetch or another site's form must not be able to trigger one.
     */
    #[Route('/durable/samples/run/{id}', name: 'durable_samples_run', methods: ['POST'])]
    public function run(string $id, Request $request): Response
    {
        if (self::isCrossSite($request)) {
            throw new AccessDeniedHttpException('Sample runs start from this application only.');
        }

        $scenario = SampleWorkflowCatalog::findById($id);
        if (null === $scenario) {
            throw $this->createNotFoundException(\sprintf('Unknown scenario: %s', $id));
        }

        $workflowType = $scenario['workflowType'];
        if (!$this->workflowRunner->hasWorkflow($workflowType)) {
            return $this->render('samples/error.html.twig', [
                'message' => \sprintf('The workflow type "%s" is not registered.', $workflowType),
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

    // ponytail: Fetch Metadata and Origin instead of a CSRF token, which would need symfony/security-csrf.
    // Enough for a local-only bench; an exposed application wants the token.
    private static function isCrossSite(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if (null !== $site) {
            return !\in_array($site, ['same-origin', 'none'], true);
        }
        $origin = $request->headers->get('Origin');

        return null !== $origin && $origin !== $request->getSchemeAndHttpHost();
    }
}
