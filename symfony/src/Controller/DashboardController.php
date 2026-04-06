<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = \trim((string) $request->query->get('q', ''));
        $status = \trim((string) $request->query->get('status', 'all'));

        $runs = $this->demoRuns();
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
     * @return list<array{
     *   runId: string,
     *   workflowName: string,
     *   status: 'running'|'completed'|'failed',
     *   taskQueue: string,
     *   startedAt: string,
     *   duration: string,
     *   events: list<array{time: string, type: string}>
     * }>
     */
    private function demoRuns(): array
    {
        return [
            [
                'runId' => 'run-20260406-001',
                'workflowName' => 'OrderFulfillment',
                'status' => 'running',
                'taskQueue' => 'default',
                'startedAt' => '2026-04-06 13:25:32',
                'duration' => '00:04:12',
                'events' => [
                    ['time' => '13:25:32', 'type' => 'WorkflowExecutionStarted'],
                    ['time' => '13:25:35', 'type' => 'ActivityTaskScheduled'],
                    ['time' => '13:29:44', 'type' => 'WorkflowTaskStarted'],
                ],
            ],
            [
                'runId' => 'run-20260406-002',
                'workflowName' => 'InvoicePipeline',
                'status' => 'completed',
                'taskQueue' => 'default',
                'startedAt' => '2026-04-06 12:58:03',
                'duration' => '00:01:47',
                'events' => [
                    ['time' => '12:58:03', 'type' => 'WorkflowExecutionStarted'],
                    ['time' => '12:58:10', 'type' => 'ActivityTaskCompleted'],
                    ['time' => '12:59:50', 'type' => 'WorkflowExecutionCompleted'],
                ],
            ],
            [
                'runId' => 'run-20260406-003',
                'workflowName' => 'BookingSaga',
                'status' => 'failed',
                'taskQueue' => 'payments',
                'startedAt' => '2026-04-06 12:40:11',
                'duration' => '00:00:53',
                'events' => [
                    ['time' => '12:40:11', 'type' => 'WorkflowExecutionStarted'],
                    ['time' => '12:40:32', 'type' => 'ActivityTaskFailed'],
                    ['time' => '12:41:04', 'type' => 'WorkflowExecutionFailed'],
                ],
            ],
            [
                'runId' => 'run-20260406-004',
                'workflowName' => 'SimpleActivity',
                'status' => 'completed',
                'taskQueue' => 'default',
                'startedAt' => '2026-04-06 11:14:08',
                'duration' => '00:00:06',
                'events' => [
                    ['time' => '11:14:08', 'type' => 'WorkflowExecutionStarted'],
                    ['time' => '11:14:10', 'type' => 'ActivityTaskCompleted'],
                    ['time' => '11:14:14', 'type' => 'WorkflowExecutionCompleted'],
                ],
            ],
            [
                'runId' => 'run-20260406-005',
                'workflowName' => 'SignalWorkflow',
                'status' => 'running',
                'taskQueue' => 'signals',
                'startedAt' => '2026-04-06 10:22:57',
                'duration' => '00:12:04',
                'events' => [
                    ['time' => '10:22:57', 'type' => 'WorkflowExecutionStarted'],
                    ['time' => '10:23:19', 'type' => 'WorkflowExecutionSignaled'],
                    ['time' => '10:34:51', 'type' => 'WorkflowTaskScheduled'],
                ],
            ],
        ];
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
