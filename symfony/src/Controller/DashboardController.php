<?php

declare(strict_types=1);

namespace App\Controller;

use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Observation\RunTimeline;
use Gplanchat\Durable\Observation\TimelineAction;
use Gplanchat\Durable\Observation\TimelineEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The bench's dashboard, drawn from {@see RunDashboard} like the Sylius plugin's and Magento's.
 *
 * The model (runs, counters, frieze) is the core's. What stays here is this page's chrome: a text
 * search over the page, lane toggles, the animation preference, and a "previous page" link, which
 * a forward-only cursor needs a stack for.
 */
final class DashboardController extends AbstractController
{
    private const ANIMATION_COOKIE_NAME = 'durable_dashboard_timelapse_animate';

    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(Request $request, RunDashboard $dashboard): Response
    {
        $query = \trim((string) $request->query->get('q', ''));
        $status = \trim((string) $request->query->get('status', 'all'));
        $animateTimelapse = $this->resolveAnimateTimelapsePreference($request);
        $availableKinds = \array_map(static fn (WorkflowRunEventKind $kind): string => $kind->value, WorkflowRunEventKind::cases());
        $visibleKinds = \array_values(\array_intersect($availableKinds, $request->query->all('kinds')));
        if ([] === $visibleKinds) {
            $visibleKinds = $availableKinds;
        }
        $cursor = \trim((string) $request->query->get('cursor', ''));
        $stackEncoded = \trim((string) $request->query->get('stack', ''));
        $cursorStack = $this->decodeCursorStack($stackEncoded);
        $selectedRunId = \trim((string) $request->query->get('run', ''));

        $view = $dashboard->build('' === $status ? 'all' : $status, '' === $cursor ? null : $cursor, '' === $selectedRunId ? null : $selectedRunId);

        // ponytail: the search filters the page the backend returned, not the whole catalog.
        $runs = \array_values(\array_filter($view['runs'], static fn (array $run): bool => '' === $query
            || false !== \stripos($run['workflowName'], $query)
            || false !== \stripos($run['executionId'], $query)));

        $selectedRun = $view['selectedRun'];
        if (null !== $selectedRun) {
            $selectedRunId = $selectedRun['executionId'];
            $selectedRun['events'] = self::events($selectedRun['timeline']);
            $selectedRun['timeline'] = self::frieze($selectedRun['timeline'], $visibleKinds, WorkflowRunStatus::Running->value === $selectedRun['status']);
        }

        $previousCursor = null;
        $previousStackEncoded = '';
        if ([] !== $cursorStack) {
            $previousStack = $cursorStack;
            $previousCursor = (string) \array_pop($previousStack);
            $previousStackEncoded = $this->encodeCursorStack($previousStack);
        }
        $nextStack = $cursorStack;
        $nextStack[] = $cursor;

        $response = $this->render('dashboard/index.html.twig', [
            'backend' => $view['backend'],
            'runs' => $runs,
            'selectedRun' => $selectedRun,
            'selectedRunId' => $selectedRunId,
            'query' => $query,
            'status' => $status,
            'statuses' => WorkflowRunStatus::cases(),
            'animateTimelapse' => $animateTimelapse,
            'kpis' => $view['kpis'],
            'timelineControls' => [
                'visibleKinds' => $visibleKinds,
                'availableKinds' => $availableKinds,
            ],
            'pagination' => [
                'hasPrevious' => null !== $previousCursor,
                'previousCursor' => $previousCursor,
                'previousStack' => $previousStackEncoded,
                'hasNext' => $view['pagination']['hasNext'],
                'nextCursor' => $view['pagination']['nextCursor'],
                'nextStack' => $this->encodeCursorStack($nextStack),
                'cursor' => $cursor,
                'stack' => $stackEncoded,
                'pageSize' => RunDashboard::PAGE_SIZE,
            ],
        ]);

        if (null !== $request->query->get('animate')) {
            $response->headers->setCookie(
                Cookie::create(self::ANIMATION_COOKIE_NAME)
                    ->withValue($animateTimelapse ? '1' : '0')
                    ->withExpires(new \DateTimeImmutable('+6 months'))
                    ->withPath('/dashboard')
            );
        }

        return $response;
    }

    /**
     * One lane per action, placed as a percentage of the recorded span. The core measures in
     * seconds and leaves the drawing to the host (DUR049).
     *
     * @param list<string> $visibleKinds
     *
     * @return array{startTime: string, endTime: string, windowDurationLabel: string, lanes: list<array<string, mixed>>}
     */
    private static function frieze(RunTimeline $timeline, array $visibleKinds, bool $running): array
    {
        $journal = $timeline->journal();
        $start = [] === $journal ? null : $journal[0]->event->recordedAt;
        $at = static fn (float $offset): string => null === $start ? '' : $start->modify(\sprintf('+%d milliseconds', (int) \round($offset * 1000)))->format('H:i:s.v');
        $span = $timeline->span > 0.0 ? $timeline->span : 1.0;

        $lanes = [];
        foreach ($timeline->actions as $action) {
            if (!\in_array($action->kind->value, $visibleKinds, true)) {
                continue;
            }
            $lanes[] = [
                'label' => $action->label,
                'kind' => $action->kind->value,
                'startPercent' => \round($action->offset / $span * 100, 3),
                // A one-instant action still gets a visible mark.
                'widthPercent' => \max(0.5, \round($action->duration / $span * 100, 3)),
                'isRunning' => $running && self::reachesTheEnd($action, $timeline->span),
                'startTime' => $at($action->offset),
                'endTime' => $at($action->offset + $action->duration),
            ];
        }

        return [
            'startTime' => $at(0.0),
            'endTime' => $at($timeline->span),
            'windowDurationLabel' => $timeline->spanLabel,
            'lanes' => $lanes,
        ];
    }

    private static function reachesTheEnd(TimelineAction $action, float $span): bool
    {
        return $action->offset + $action->duration >= $span;
    }

    /**
     * @return list<array{eventId: int, time: string, category: string, type: string}>
     */
    private static function events(RunTimeline $timeline): array
    {
        return \array_map(static fn (TimelineEvent $row): array => [
            'eventId' => $row->event->sequence,
            'time' => $row->event->recordedAt->format('H:i:s.v'),
            'category' => $row->event->kind->value,
            'type' => $row->event->label,
        ], $timeline->journal());
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

        return \rtrim(\strtr(\base64_encode(\json_encode($stack, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function resolveAnimateTimelapsePreference(Request $request): bool
    {
        $preference = $request->query->get('animate') ?? $request->cookies->get(self::ANIMATION_COOKIE_NAME);
        if (!\is_string($preference)) {
            return true;
        }

        return !\in_array(\strtolower(\trim($preference)), ['0', 'false', 'off', 'no'], true);
    }
}
