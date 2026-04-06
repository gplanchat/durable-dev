<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dashboard\TemporalEventsDashboardDataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(Request $request, TemporalEventsDashboardDataProvider $dataProvider): Response
    {
        $query = \trim((string) $request->query->get('q', ''));
        $status = \trim((string) $request->query->get('status', 'all'));

        $runs = $dataProvider->provideRuns();
        $filteredRuns = \array_values(\array_filter($runs, static function (array $run) use ($query, $status): bool {
            $matchesStatus = 'all' === $status || $run['status'] === $status;
            $matchesQuery = '' === $query
                || false !== \stripos($run['workflowName'], $query)
                || false !== \stripos($run['runId'], $query)
                || false !== \stripos($run['taskQueue'], $query);

            return $matchesStatus && $matchesQuery;
        }));

        $selectedRunId = (string) $request->query->get('run', '');
        $selectedRun = null;
        foreach ($filteredRuns as $run) {
            if ($run['runId'] === $selectedRunId) {
                $selectedRun = $run;
                break;
            }
        }

        if (null === $selectedRun && [] !== $filteredRuns) {
            $selectedRun = $filteredRuns[0];
            $selectedRunId = $selectedRun['runId'];
        }

        return $this->render('dashboard/index.html.twig', [
            'runs' => $filteredRuns,
            'selectedRun' => $selectedRun,
            'selectedRunId' => $selectedRunId,
            'query' => $query,
            'status' => $status,
            'kpis' => $this->buildKpis($filteredRuns),
        ]);
    }

    /**
     * @param list<array{status: string}> $runs
     *
     * @return array{total: int, running: int, completed: int, failed: int}
     */
    private function buildKpis(array $runs): array
    {
        $total = \count($runs);
        $running = 0;
        $completed = 0;
        $failed = 0;

        foreach ($runs as $run) {
            if ('running' === $run['status']) {
                ++$running;
                continue;
            }

            if ('completed' === $run['status']) {
                ++$completed;
                continue;
            }

            if ('failed' === $run['status']) {
                ++$failed;
            }
        }

        return [
            'total' => $total,
            'running' => $running,
            'completed' => $completed,
            'failed' => $failed,
        ];
    }
}
