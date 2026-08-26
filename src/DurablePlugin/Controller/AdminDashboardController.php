<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Controller;

use Gplanchat\Durable\Plugin\Dashboard\TemporalEventsDashboardDataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

final class AdminDashboardController
{
    private const DASHBOARD_PAGE_SIZE = 20;

    /** @var list<string> */
    private const TIMELINE_KINDS = ['execution', 'activity', 'signal', 'query', 'update'];

    public function __construct(
        private readonly Environment $twig,
    ) {}

    #[IsGranted('ROLE_ADMINISTRATION_ACCESS')]
    public function index(Request $request, TemporalEventsDashboardDataProvider $dataProvider): Response
    {
        $query = \trim((string) $request->query->get('q', ''));
        $status = \trim((string) $request->query->get('status', 'all'));
        $kinds = $request->query->all('kinds');
        if ([] === $kinds) {
            $visibleKinds = self::TIMELINE_KINDS;
        } else {
            $visibleKinds = \array_values(\array_filter(
                self::TIMELINE_KINDS,
                static fn(string $kind): bool => \in_array($kind, $kinds, true),
            ));
            if ([] === $visibleKinds) {
                $visibleKinds = self::TIMELINE_KINDS;
            }
        }

        $cursor = \trim((string) $request->query->get('cursor', ''));
        $page = $dataProvider->provideRunsPage($cursor, self::DASHBOARD_PAGE_SIZE, $status);
        $runs = $page['runs'];
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

        if (null !== $selectedRun) {
            $selectedRun = $dataProvider->enrichWithHistory($selectedRun, 'all', $visibleKinds);
        }

        return new Response($this->twig->render('@DurablePlugin/admin/dashboard/index.html.twig', [
            'runs' => $filteredRuns,
            'selectedRun' => $selectedRun,
            'selectedRunId' => $selectedRunId,
            'query' => $query,
            'status' => $status,
            'kpis' => $this->buildKpis($filteredRuns),
            'timelineControls' => [
                'visibleKinds' => $visibleKinds,
                'availableKinds' => self::TIMELINE_KINDS,
            ],
            'pagination' => [
                'hasNext' => null !== $page['nextCursor'],
                'nextCursor' => $page['nextCursor'],
                'cursor' => $cursor,
                'pageSize' => self::DASHBOARD_PAGE_SIZE,
            ],
            'temporal' => $page['temporal'],
        ]));
    }

    /**
     * @param list<array{status: string, ...}> $runs
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
