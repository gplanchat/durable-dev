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
    private const DASHBOARD_PAGE_SIZE = 20;

    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(Request $request, TemporalEventsDashboardDataProvider $dataProvider): Response
    {
        $query = \trim((string) $request->query->get('q', ''));
        $status = \trim((string) $request->query->get('status', 'all'));
        $cursor = \trim((string) $request->query->get('cursor', ''));
        $stackEncoded = \trim((string) $request->query->get('stack', ''));
        $cursorStack = $this->decodeCursorStack($stackEncoded);

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
            $selectedRun = $dataProvider->enrichWithHistory($selectedRun);
        }

        $previousCursor = null;
        $previousStackEncoded = '';
        if ([] !== $cursorStack) {
            $previousStack = $cursorStack;
            $previousCursor = (string) \array_pop($previousStack);
            $previousStackEncoded = $this->encodeCursorStack($previousStack);
        }

        $nextCursor = $page['nextCursor'];
        $nextStack = $cursorStack;
        $nextStack[] = $cursor;
        $nextStackEncoded = $this->encodeCursorStack($nextStack);

        return $this->render('dashboard/index.html.twig', [
            'runs' => $filteredRuns,
            'selectedRun' => $selectedRun,
            'selectedRunId' => $selectedRunId,
            'query' => $query,
            'status' => $status,
            'kpis' => $this->buildKpis($filteredRuns),
            'pagination' => [
                'hasPrevious' => null !== $previousCursor,
                'previousCursor' => $previousCursor,
                'previousStack' => $previousStackEncoded,
                'hasNext' => null !== $nextCursor,
                'nextCursor' => $nextCursor,
                'nextStack' => $nextStackEncoded,
                'cursor' => $cursor,
                'stack' => $stackEncoded,
                'cursorHint' => '' === $cursor ? 'START' : $this->cursorHint($cursor),
                'nextCursorHint' => null !== $nextCursor ? $this->cursorHint($nextCursor) : null,
                'pageSize' => self::DASHBOARD_PAGE_SIZE,
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function decodeCursorStack(string $encoded): array
    {
        if ('' === $encoded) {
            return [];
        }

        $normalized = \strtr($encoded, '-_', '+/');
        $pad = \strlen($normalized) % 4;
        if (0 !== $pad) {
            $normalized .= \str_repeat('=', 4 - $pad);
        }

        $decoded = \base64_decode($normalized, true);
        if (false === $decoded) {
            return [];
        }

        $data = \json_decode($decoded, true);
        if (!\is_array($data)) {
            return [];
        }

        return \array_values(\array_filter($data, static fn (mixed $v): bool => \is_string($v)));
    }

    /**
     * @param list<string> $stack
     */
    private function encodeCursorStack(array $stack): string
    {
        if ([] === $stack) {
            return '';
        }

        $json = \json_encode($stack, \JSON_THROW_ON_ERROR);

        return \rtrim(\strtr(\base64_encode($json), '+/', '-_'), '=');
    }

    private function cursorHint(string $cursor): string
    {
        return \substr(\sha1($cursor), 0, 8);
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
