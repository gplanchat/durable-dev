<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DataCollector;

use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Bundle\Profiler\DurableProfilerEventPresentation;
use Gplanchat\Durable\Bundle\Profiler\DurableProfilerTimeframe;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Panneau profiler Durable : executionIds issus des dispatches Messenger sur la requête (ou query durable_execution),
 * puis historique lu dans l’event store.
 *
 * La trace mémoire enregistre les envois WorkflowRunMessage, chaque run moteur ({@see WorkflowExecutionObserverInterface})
 * et chaque activité exécutée dans ce processus ; le détail complet du journal vient de l’event store.
 *
 * Pour inclure un journal sans dispatch sur cette requête, ajouter durable_execution (UUID, virgules si plusieurs).
 */
final class DurableDataCollector extends DataCollector implements ResetInterface
{
    private const MAX_STORE_EVENTS_PER_STREAM = 500;

    public function __construct(
        private readonly DurableExecutionTrace $trace,
        private readonly WorkflowMetadataStore $metadataStore,
        private readonly EventStoreInterface $eventStore,
    ) {
    }

    #[\Override]
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $timeline = $this->enrichTimelineForProfiler($this->trace->getTimeline());
        $executionIds = $this->collectDispatchedExecutionIdsFromTimeline($timeline);
        $executionIds = $this->mergeExecutionIdsFromRequest($request, $executionIds);

        $runSnapshots = [];
        foreach ($timeline as $entry) {
            if (($entry['kind'] ?? '') !== 'dispatch') {
                continue;
            }
            $eid = (string) ($entry['executionId'] ?? '');
            if ('' === $eid) {
                continue;
            }
            $runSnapshots[$eid] = [
                'metadata' => $this->metadataStore->get($eid),
                'eventCount' => $this->eventStore->countEventsInStream($eid),
            ];
        }

        foreach (array_keys($executionIds) as $eid) {
            if (!isset($runSnapshots[$eid])) {
                $runSnapshots[$eid] = [
                    'metadata' => $this->metadataStore->get($eid),
                    'eventCount' => $this->eventStore->countEventsInStream($eid),
                ];
            }
        }

        $executionIdsList = array_keys($executionIds);
        sort($executionIdsList);

        $timeFrameProcess = $this->buildTimeFrameModelFromTimeline($timeline);
        $storeTimelines = $this->buildStoreTimelines($executionIdsList);
        $storeEventRows = $this->collectStoreEventRows($executionIdsList);
        $grouped = $this->groupTimelineByExecution($timeline);

        $this->data = [
            'timeline' => $timeline,
            'journal_event_count' => $this->totalJournalEventCount($executionIdsList),
            'dispatch_count' => $this->trace->countDispatchEvents(),
            'run_snapshots' => $runSnapshots,
            'executions' => $grouped,
            'execution_ids' => $executionIdsList,
            'time_frame' => [
                'process' => $timeFrameProcess,
                'store_timelines' => $storeTimelines,
            ],
            'store_event_rows' => $storeEventRows,
            'executions_detail' => $this->buildExecutionsDetail(
                $executionIdsList,
                $storeTimelines,
                $storeEventRows,
                $timeFrameProcess,
                $grouped,
            ),
        ];
    }

    /**
     * @param list<string> $executionIdsList
     * @param list<array<string, mixed>> $storeTimelines
     * @param list<array<string, mixed>> $storeEventRows
     * @param array{bounds: array{tMin: float, tMax: float, spanSec: float}|null, segments: list<array<string, mixed>>} $timeFrameProcess
     * @param array<string, list<array<string, mixed>>> $groupedTimeline
     *
     * @return list<array<string, mixed>>
     */
    private function buildExecutionsDetail(
        array $executionIdsList,
        array $storeTimelines,
        array $storeEventRows,
        array $timeFrameProcess,
        array $groupedTimeline,
    ): array {
        $out = [];
        foreach ($executionIdsList as $eid) {
            $wf = null;
            $payload = [];
            $meta = $this->metadataStore->get($eid);
            if (\is_array($meta)) {
                if (isset($meta['workflowType']) && '' !== (string) $meta['workflowType']) {
                    $wf = (string) $meta['workflowType'];
                }
                if (isset($meta['payload']) && \is_array($meta['payload'])) {
                    $payload = $meta['payload'];
                }
            }

            $timelineForExec = $groupedTimeline[$eid] ?? [];
            if ([] === $payload) {
                $payload = $this->inferPayloadFromTimelineDispatch($timelineForExec);
            }

            $storeTl = null;
            foreach ($storeTimelines as $st) {
                if (($st['executionId'] ?? '') === $eid) {
                    $storeTl = $st;
                    break;
                }
            }

            $rows = [];
            foreach ($storeEventRows as $row) {
                if (($row['executionId'] ?? '') === $eid) {
                    $rows[] = $row;
                }
            }

            $processTf = $this->filterProcessTimeframeForExecution($timeFrameProcess, $eid);

            $storeCountFromIndex = (int) ($storeTl['eventCount'] ?? 0);
            $storeCountLive = $this->eventStore->countEventsInStream($eid);
            $timelineHasDispatch = $this->timelineHasDispatchForExecution($timelineForExec);
            $statusCode = $this->resolveExecutionStatus($eid, $rows, $meta, $timelineHasDispatch);
            $out[] = [
                'executionId' => $eid,
                'workflowType' => $wf,
                'payloadSummary' => $this->summarizePayload($payload),
                'executionStatus' => $statusCode,
                'executionStatusLabel' => $this->executionStatusLabelFr($statusCode),
                'storeEventCount' => max($storeCountFromIndex, $storeCountLive),
                'storeTruncated' => $storeTl['truncated'] ?? false,
                'processTraceCount' => \count($processTf['segments'] ?? []),
                'processTimeframe' => $processTf,
                'storeTimeline' => $storeTl,
                'storeRows' => $rows,
                'timelineEntries' => $groupedTimeline[$eid] ?? [],
                'journalHint' => $this->buildJournalHint($rows, $storeCountLive, $timelineHasDispatch),
            ];
        }

        return $out;
    }

    /**
     * Statut : journal ({@see collectStoreEventRows}), puis métadonnées, puis relecture du store
     * (même fichier SQLite / autre processus worker que la requête HTTP).
     *
     * @param list<array<string, mixed>> $rows lignes {@see collectStoreEventRows} pour cet executionId
     */
    private function resolveExecutionStatus(
        string $executionId,
        array $rows,
        ?array $metadata,
        bool $timelineHasDispatch,
    ): string {
        if ([] !== $rows) {
            return $this->inferExecutionStatusFromLastStoreRow($rows);
        }

        if (\is_array($metadata) && ($metadata['completed'] ?? false) === true) {
            return 'completed';
        }

        $n = $this->eventStore->countEventsInStream($executionId);
        if ($n > 0) {
            $lastEvent = null;
            foreach ($this->eventStore->readStreamWithRecordedAt($executionId) as $entry) {
                $lastEvent = $entry['event'];
            }
            if (null !== $lastEvent) {
                return $this->mapEventShortNameToStatus((new \ReflectionClass($lastEvent))->getShortName());
            }
        }

        if (\is_array($metadata) && ($metadata['completed'] ?? false) === false && $this->metadataStore->hasActiveWorkflowMetadata($executionId)) {
            return 'running';
        }

        if ($timelineHasDispatch && 0 === $n) {
            return 'queued';
        }

        return 'pending';
    }

    /**
     * @param list<array<string, mixed>> $timelineForExec
     */
    private function timelineHasDispatchForExecution(array $timelineForExec): bool
    {
        foreach ($timelineForExec as $e) {
            if ('dispatch' === ($e['kind'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function buildJournalHint(array $rows, int $storeCountLive, bool $timelineHasDispatch): ?string
    {
        if ([] !== $rows || $storeCountLive > 0) {
            return null;
        }
        if (!$timelineHasDispatch) {
            return null;
        }

        return 'Un dispatch WorkflowRunMessage a été observé sur cette requête, mais le journal est encore vide : '
            .'le handler n’a probablement pas encore tourné dans ce processus (Messenger asynchrone). '
            .'Pour remplir le journal dans le même profil, utilisez la démo avec attente (drain) ou rechargez avec '
            .'?durable_execution=&lt;uuid&gt; une fois les workers passés.';
    }

    /**
     * @param list<array<string, mixed>> $rows lignes {@see collectStoreEventRows} pour cet executionId
     */
    private function inferExecutionStatusFromLastStoreRow(array $rows): string
    {
        $last = $rows[\count($rows) - 1];

        return $this->mapEventShortNameToStatus((string) ($last['type'] ?? ''));
    }

    private function mapEventShortNameToStatus(string $shortName): string
    {
        return match ($shortName) {
            'ExecutionCompleted' => 'completed',
            'WorkflowExecutionFailed' => 'failed',
            'WorkflowContinuedAsNew' => 'continued_as_new',
            'WorkflowCancellationRequested' => 'cancel_requested',
            default => 'running',
        };
    }

    private function executionStatusLabelFr(string $code): string
    {
        return match ($code) {
            'completed' => 'Terminé',
            'failed' => 'Échec',
            'continued_as_new' => 'Continue as new',
            'cancel_requested' => 'Annulation demandée',
            'running' => 'En cours',
            'queued' => 'En file (pas encore de journal)',
            'pending' => 'En attente',
            default => 'Inconnu',
        };
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return array<string, mixed>
     */
    private function inferPayloadFromTimelineDispatch(array $entries): array
    {
        foreach ($entries as $e) {
            if ('dispatch' === ($e['kind'] ?? '') && isset($e['payload']) && \is_array($e['payload'])) {
                return $e['payload'];
            }
        }

        return [];
    }

    /**
     * @param array{bounds: array{tMin: float, tMax: float, spanSec: float}|null, segments: list<array<string, mixed>>} $fullProcess
     *
     * @return array{bounds: array{tMin: float, tMax: float, spanSec: float}|null, segments: list<array<string, mixed>>}
     */
    private function filterProcessTimeframeForExecution(array $fullProcess, string $eid): array
    {
        $segments = $fullProcess['segments'] ?? [];
        if ([] === $segments) {
            return ['bounds' => null, 'segments' => []];
        }

        $raw = [];
        foreach ($segments as $s) {
            if (($s['executionId'] ?? '') !== $eid) {
                continue;
            }
            $raw[] = [
                'executionId' => $s['executionId'],
                'kind' => $s['kind'],
                'seq' => $s['seq'],
                'label' => $s['label'],
                'startSec' => $s['startSec'],
                'endSec' => $s['endSec'],
                'durationMs' => $s['durationMs'],
                'source' => $s['source'] ?? 'process',
                'display_title' => $s['display_title'] ?? $s['label'],
                'display_subtitle' => $s['display_subtitle'] ?? '',
                'category' => $s['category'] ?? 'default',
            ];
        }

        return $this->finalizeTimeFrameSegments($raw);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summarizePayload(array $payload): string
    {
        if ([] === $payload) {
            return '—';
        }
        try {
            $j = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return '…';
        }
        if (\strlen($j) > 140) {
            return substr($j, 0, 137).'…';
        }

        return $j;
    }

    /**
     * @param list<array<string, mixed>> $timeline
     *
     * @return list<array<string, mixed>>
     */
    private function enrichTimelineForProfiler(array $timeline): array
    {
        foreach ($timeline as $i => $e) {
            $kind = (string) ($e['kind'] ?? '');
            if ('dispatch' === $kind) {
                $timeline[$i]['dispatchSummary'] = DurableProfilerEventPresentation::dispatchTimelineLabel($e);
            }
            if ('workflow' === $kind) {
                $wt = trim((string) ($e['workflowType'] ?? ''));
                $timeline[$i]['dispatchSummary'] = ($e['isResume'] ?? false)
                    ? 'Reprise moteur · '.('' !== $wt ? $wt : '(type inconnu)')
                    : 'Démarrage moteur · '.('' !== $wt ? $wt : '(type inconnu)');
            }
            if ('activity' === $kind) {
                $timeline[$i]['dispatchSummary'] = ($e['activityName'] ?? '?').' · '.($e['activityId'] ?? '?')
                    .(empty($e['success']) && \array_key_exists('success', $e) ? ' · échec' : '');
            }
        }

        return $timeline;
    }

    /**
     * Identifiants issus de la trace processus (dispatch Messenger, run moteur, activités — ex. worker sans dispatch workflow sur cette requête).
     *
     * @param list<array<string, mixed>> $timeline
     *
     * @return array<string, bool>
     */
    private function collectDispatchedExecutionIdsFromTimeline(array $timeline): array
    {
        $ids = [];
        foreach ($timeline as $entry) {
            $id = (string) ($entry['executionId'] ?? '');
            if ('' !== $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $executionIdsList
     */
    private function totalJournalEventCount(array $executionIdsList): int
    {
        $n = 0;
        foreach ($executionIdsList as $eid) {
            $n += $this->eventStore->countEventsInStream($eid);
        }

        return $n;
    }

    /**
     * Inclut des exécutions à afficher depuis la query (journal event store même sans dispatch observé sur cette requête).
     *
     * @param array<string, bool> $executionIds
     *
     * @return array<string, bool>
     */
    private function mergeExecutionIdsFromRequest(Request $request, array $executionIds): array
    {
        $raw = $request->query->get('durable_execution');
        if (!\is_string($raw)) {
            return $executionIds;
        }
        $raw = trim($raw);
        if ('' === $raw) {
            return $executionIds;
        }

        foreach (explode(',', $raw) as $part) {
            $id = trim($part);
            if ('' !== $id) {
                $executionIds[$id] = true;
            }
        }

        return $executionIds;
    }

    /**
     * @param list<string> $executionIds
     *
     * @return list<array{executionId: string, bounds: array{tMin: float, tMax: float, spanSec: float}|null, segments: list<array<string, mixed>>, eventCount: int, truncated: bool}>
     */
    private function buildStoreTimelines(array $executionIds): array
    {
        $out = [];
        foreach ($executionIds as $eid) {
            $entries = [];
            $truncated = false;
            foreach ($this->eventStore->readStreamWithRecordedAt($eid) as $entry) {
                if (\count($entries) >= self::MAX_STORE_EVENTS_PER_STREAM) {
                    $truncated = true;
                    break;
                }
                $entries[] = $entry;
            }

            if ([] === $entries) {
                continue;
            }

            $out[] = [
                'executionId' => $eid,
                'bounds' => null,
                'segments' => [],
                'eventCount' => \count($entries),
                'truncated' => $truncated,
            ];
            $idx = \count($out) - 1;
            $model = $this->buildTimeFrameModelFromStoreEvents($eid, $entries);
            $out[$idx]['bounds'] = $model['bounds'];
            $out[$idx]['segments'] = $model['segments'];
        }

        return $out;
    }

    /**
     * @param list<array{event: Event, recordedAt: \DateTimeImmutable|null}> $entries
     *
     * @return array{bounds: array{tMin: float, tMax: float, spanSec: float}|null, segments: list<array<string, mixed>>}
     */
    private function buildTimeFrameModelFromStoreEvents(string $executionId, array $entries): array
    {
        $timeRows = array_map(
            static fn (array $e): array => ['recordedAt' => $e['recordedAt'] ?? null],
            $entries,
        );
        $times = DurableProfilerTimeframe::monotonicUnixSecondsFromRecordedEntries($timeRows);
        $n = \count($entries);
        $raw = [];
        for ($i = 0; $i < $n; ++$i) {
            $event = $entries[$i]['event'];
            $start = $times[$i];
            $end = $i + 1 < $n ? $times[$i + 1] : $start + DurableProfilerTimeframe::MIN_SEGMENT_SEC;
            if ($end <= $start) {
                $end = $start + DurableProfilerTimeframe::MIN_SEGMENT_SEC;
            }
            $kind = $this->mapEventToBarKind($event);
            $p = DurableProfilerEventPresentation::fromStoreEvent($event);
            $raw[] = [
                'executionId' => $executionId,
                'kind' => $kind,
                'seq' => $i + 1,
                'label' => $p['title'],
                'display_title' => $p['title'],
                'display_subtitle' => $p['subtitle'],
                'category' => $p['category'],
                'technical' => $p['technical'],
                'startSec' => $start,
                'endSec' => $end,
                'durationMs' => ($end - $start) * 1000.0,
                'source' => 'store',
            ];
        }

        return $this->finalizeTimeFrameSegments($raw);
    }

    /**
     * @param list<array<string, mixed>> $raw
     *
     * @return array{bounds: array{tMin: float, tMax: float, spanSec: float}|null, segments: list<array<string, mixed>>}
     */
    private function finalizeTimeFrameSegments(array $raw): array
    {
        if ([] === $raw) {
            return ['bounds' => null, 'segments' => []];
        }

        $starts = array_map(static fn (array $s): float => $s['startSec'], $raw);
        $ends = array_map(static fn (array $s): float => $s['endSec'], $raw);
        $tMin = min($starts);
        $tMax = max($ends);
        $span = $tMax - $tMin;
        $pad = $span > 0 ? $span * 0.02 : 0.001;
        $tMin -= $pad;
        $tMax += $pad;
        $span = $tMax - $tMin;

        $segments = [];
        foreach ($raw as $s) {
            $left = $span > 0 ? (($s['startSec'] - $tMin) / $span) * 100.0 : 0.0;
            $width = $span > 0 ? (($s['endSec'] - $s['startSec']) / $span) * 100.0 : 100.0;
            $left = max(0.0, min(100.0, $left));
            $width = max(0.05, min(100.0 - $left, $width));

            $segments[] = array_merge($s, [
                'leftPercent' => $left,
                'widthPercent' => $width,
            ]);
        }

        return [
            'bounds' => [
                'tMin' => $tMin,
                'tMax' => $tMax,
                'spanSec' => $span,
            ],
            'segments' => $segments,
        ];
    }

    private function mapEventToBarKind(Event $event): string
    {
        return match (true) {
            $event instanceof ExecutionStarted,
            $event instanceof ExecutionCompleted,
            $event instanceof WorkflowExecutionFailed,
            $event instanceof WorkflowContinuedAsNew,
            $event instanceof WorkflowCancellationRequested => 'workflow',
            $event instanceof ActivityScheduled,
            $event instanceof ChildWorkflowScheduled,
            $event instanceof TimerScheduled => 'dispatch',
            $event instanceof ActivityCompleted,
            $event instanceof ActivityFailed,
            $event instanceof ActivityCancelled,
            $event instanceof TimerCompleted,
            $event instanceof SideEffectRecorded,
            $event instanceof ChildWorkflowCompleted,
            $event instanceof WorkflowSignalReceived,
            $event instanceof WorkflowUpdateHandled => 'activity',
            default => 'default',
        };
    }

    /**
     * @param list<string> $executionIds
     *
     * @return list<array<string, mixed>>
     */
    private function collectStoreEventRows(array $executionIds): array
    {
        $rows = [];
        foreach ($executionIds as $eid) {
            $i = 0;
            foreach ($this->eventStore->readStreamWithRecordedAt($eid) as $entry) {
                if ($i >= self::MAX_STORE_EVENTS_PER_STREAM) {
                    break;
                }
                $event = $entry['event'];
                $recordedAt = $entry['recordedAt'];
                $p = DurableProfilerEventPresentation::fromStoreEvent($event);
                $rows[] = [
                    'executionId' => $eid,
                    'index' => $i,
                    'type' => $p['technical'],
                    'label' => $p['title'],
                    'title' => $p['title'],
                    'subtitle' => $p['subtitle'],
                    'category' => $p['category'],
                    'payload' => $event->payload(),
                    'recordedAt' => null !== $recordedAt ? $recordedAt->format(\DateTimeInterface::ATOM) : null,
                ];
                ++$i;
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $timeline
     *
     * @return array{bounds: array{tMin: float, tMax: float, spanSec: float}|null, segments: list<array<string, mixed>>}
     */
    private function buildTimeFrameModelFromTimeline(array $timeline): array
    {
        if ([] === $timeline) {
            return ['bounds' => null, 'segments' => []];
        }

        $ordered = $timeline;
        usort($ordered, static fn (array $a, array $b): int => ($a['seq'] ?? 0) <=> ($b['seq'] ?? 0));

        $n = \count($ordered);
        $raw = [];
        for ($i = 0; $i < $n; ++$i) {
            $e = $ordered[$i];
            $at = (float) ($e['at'] ?? 0.0);
            $kind = (string) ($e['kind'] ?? '');
            $nextAt = $i + 1 < $n ? (float) ($ordered[$i + 1]['at'] ?? $at) : null;

            $bounds = DurableProfilerTimeframe::boundsForProcessTraceEntry(
                $at,
                $nextAt,
                $kind,
                (float) ($e['durationSeconds'] ?? 0.0),
            );
            $start = $bounds['startSec'];
            $end = $bounds['endSec'];

            $pr = DurableProfilerEventPresentation::fromProcessTrace($e);
            $raw[] = [
                'executionId' => (string) ($e['executionId'] ?? ''),
                'kind' => $kind,
                'seq' => (int) ($e['seq'] ?? 0),
                'label' => $pr['title'],
                'display_title' => $pr['title'],
                'display_subtitle' => $pr['subtitle'],
                'category' => $pr['category'],
                'startSec' => $start,
                'endSec' => $end,
                'durationMs' => ($end - $start) * 1000.0,
                'source' => 'process',
            ];
        }

        return $this->finalizeTimeFrameSegments($raw);
    }

    /**
     * @param list<array<string, mixed>> $timeline
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupTimelineByExecution(array $timeline): array
    {
        $by = [];
        foreach ($timeline as $entry) {
            $id = (string) ($entry['executionId'] ?? '');
            if ('' === $id) {
                continue;
            }
            $by[$id][] = $entry;
        }

        return $by;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTimeline(): array
    {
        return $this->data['timeline'] ?? [];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function getExecutions(): array
    {
        return $this->data['executions'] ?? [];
    }

    /**
     * @return array<string, array{metadata: array{workflowType: string, payload: array<string, mixed>}|null, eventCount: int}>
     */
    public function getRunSnapshots(): array
    {
        return $this->data['run_snapshots'] ?? [];
    }

    /**
     * Nombre total d’événements dans le journal (event store) pour tous les executionIds collectés.
     */
    public function getJournalEventCount(): int
    {
        return (int) ($this->data['journal_event_count'] ?? 0);
    }

    public function getDispatchCount(): int
    {
        return (int) ($this->data['dispatch_count'] ?? 0);
    }

    /**
     * @return list<string>
     */
    public function getExecutionIds(): array
    {
        return $this->data['execution_ids'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getStoreEventRows(): array
    {
        return $this->data['store_event_rows'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getExecutionsDetail(): array
    {
        return $this->data['executions_detail'] ?? [];
    }

    /**
     * @return array{
     *     process: array{bounds: array{tMin: float, tMax: float, spanSec: float}|null, segments: list<array<string, mixed>>},
     *     store_timelines: list<array<string, mixed>>
     * }|array{bounds: array{tMin: float, tMax: float, spanSec: float}|null, segments: list<array<string, mixed>>}
     */
    public function getTimeFrame(): array
    {
        $tf = $this->data['time_frame'] ?? null;
        if (!\is_array($tf)) {
            return [
                'process' => ['bounds' => null, 'segments' => []],
                'store_timelines' => [],
            ];
        }
        if (isset($tf['bounds'], $tf['segments']) && !isset($tf['process'])) {
            return [
                'process' => [
                    'bounds' => $tf['bounds'],
                    'segments' => \is_array($tf['segments'] ?? null) ? $tf['segments'] : [],
                ],
                'store_timelines' => [],
            ];
        }

        $process = \is_array($tf['process'] ?? null) ? $tf['process'] : [];

        return [
            'process' => [
                'bounds' => $process['bounds'] ?? null,
                'segments' => \is_array($process['segments'] ?? null) ? $process['segments'] : [],
            ],
            'store_timelines' => \is_array($tf['store_timelines'] ?? null) ? $tf['store_timelines'] : [],
        ];
    }

    #[\Override]
    public function getName(): string
    {
        return 'durable';
    }

    public static function getTemplate(): string
    {
        return '@Durable/Collector/durable.html.twig';
    }

    #[\Override]
    public function reset(): void
    {
        $this->data = [];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function __serialize(): array
    {
        $d = $this->data;

        return \is_array($d) ? $d : [];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[\Override]
    public function __unserialize(array $data): void
    {
        $this->data = $data;
    }
}
