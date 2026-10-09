<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DataCollector;

use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Bundle\Profiler\DurableProfilerEventPresentation;
use Gplanchat\Durable\Bundle\Profiler\DurableProfilerTimeframe;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\KeyPatternPayloadRedactor;
use Gplanchat\Durable\Observation\NexusOperationSummary;
use Gplanchat\Durable\Observation\PayloadRedactorInterface;
use Gplanchat\Durable\Observation\RecordedDetails;
use Gplanchat\Durable\Observation\RunTimeline;
use Gplanchat\Durable\Observation\TimelineAction;
use Gplanchat\Durable\Observation\TimelineEvent;
use Gplanchat\Durable\Observation\TimelineSegment;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Durable profiler panel: executionIds coming from the Messenger dispatches on the request (or the durable_execution query),
 * then the history read from the event store.
 *
 * The in-memory trace records the ResumeWorkflowMessage dispatches, every engine run ({@see WorkflowExecutionObserverInterface})
 * and every activity executed in this process; the full detail of the journal comes from the event store.
 *
 * To include a journal with no dispatch on this request, add durable_execution (ids, comma-separated, at most
 * {@see self::MAX_QUERIED_EXECUTIONS}).
 */
final class DurableDataCollector extends DataCollector implements ResetInterface
{
    private const MAX_STORE_EVENTS_PER_STREAM = 500;

    /** Each id named in `?durable_execution=` costs a journal read. */
    public const MAX_QUERIED_EXECUTIONS = 20;

    /**
     * Each journal of this request, read once: the first events for the panel, the last one for
     * the status, the count. Emptied when collect() returns; only `$this->data` is serialised.
     *
     * @var array<string, array{entries: list<array{event: Event, recordedAt: ?\DateTimeImmutable}>, truncated: bool, count: int, last: ?Event}>
     */
    private array $journals = [];

    public function __construct(
        private readonly DurableExecutionTrace $trace,
        private readonly WorkflowMetadataStore $metadataStore,
        private readonly EventStoreInterface $eventStore,
        private readonly PayloadRedactorInterface $redactor = new KeyPatternPayloadRedactor(),
        // Where the panel reads what a suspended run waits on (#324), by the run's id; none when
        // no backend is readable.
        private readonly ?WorkflowRunCatalogInterface $runCatalog = null,
    ) {}

    #[\Override]
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->journals = [];
        // Payloads are masked where they are copied in, never across `$this->data`: the maps keyed
        // by execution id would lose a run whose id matches the pattern ("password-reset-42").
        $timeline = array_map(
            fn(array $entry): array => \array_key_exists('payload', $entry) ? ['payload' => $this->redacted($entry['payload'])] + $entry : $entry,
            $this->enrichTimelineForProfiler($this->trace->getTimeline()),
        );
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
                'metadata' => $this->metadata($eid),
                'eventCount' => $this->journal($eid)['count'],
            ];
        }

        foreach (array_keys($executionIds) as $eid) {
            if (!isset($runSnapshots[$eid])) {
                $runSnapshots[$eid] = [
                    'metadata' => $this->metadata($eid),
                    'eventCount' => $this->journal($eid)['count'],
                ];
            }
        }

        $executionIdsList = array_keys($executionIds);
        sort($executionIdsList);

        $timeFrameProcess = $this->buildTimeFrameModelFromTimeline($timeline);
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
            ],
            'store_event_rows' => $storeEventRows,
            'executions_detail' => $this->buildExecutionsDetail(
                $executionIdsList,
                $storeEventRows,
                $timeFrameProcess,
                $grouped,
            ),
        ];

        // The barrier, at the one place `$this->data` is built. It applies **key by key**: a
        // pathological payload makes its own panel disappear, not the whole collector, which is
        // what the blanket barrier did not guarantee, `$this->data` being typed
        // `array|Data` on the parent.
        foreach ($this->data as $key => $value) {
            $this->data[$key] = RecordedDetails::storable($value);
        }
        $this->journals = [];
    }

    /**
     * @param list<string>                                                                                              $executionIdsList
     * @param list<array<string, mixed>>                                                                                $storeEventRows
     * @param array{bounds: array{tMin: float, tMax: float, spanSec: float}|null, segments: list<array<string, mixed>>} $timeFrameProcess
     * @param array<string, list<array<string, mixed>>>                                                                 $groupedTimeline
     *
     * @return list<array<string, mixed>>
     */
    private function buildExecutionsDetail(
        array $executionIdsList,
        array $storeEventRows,
        array $timeFrameProcess,
        array $groupedTimeline,
    ): array {
        $out = [];
        foreach ($executionIdsList as $eid) {
            $wf = null;
            $payload = [];
            $meta = $this->metadata($eid);
            if (null !== $meta) {
                if ('' !== $meta['workflowType']) {
                    $wf = $meta['workflowType'];
                }
                $payload = $meta['payload'];
            }

            $timelineForExec = $groupedTimeline[$eid] ?? [];
            if ([] === $payload) {
                $payload = $this->inferPayloadFromTimelineDispatch($timelineForExec);
            }

            $rows = [];
            foreach ($storeEventRows as $row) {
                if (($row['executionId'] ?? '') === $eid) {
                    $rows[] = $row;
                }
            }

            $processTf = $this->filterProcessTimeframeForExecution($timeFrameProcess, $eid);

            $journal = $this->journal($eid);
            $storeCountLive = $journal['count'];
            $timelineHasDispatch = $this->timelineHasDispatchForExecution($timelineForExec);
            $statusCode = $this->resolveExecutionStatus($eid, $rows, $meta, $timelineHasDispatch);
            $out[] = [
                'executionId' => $eid,
                'workflowType' => $wf,
                'payloadSummary' => $this->summarizePayload($payload),
                'executionStatus' => $statusCode,
                'executionStatusLabel' => $this->executionStatusLabel($statusCode),
                'waitingOn' => $this->runCatalog?->findRun(ExecutionId::fromString($eid))?->waitingOn,
                'storeEventCount' => max(\count($journal['entries']), $storeCountLive),
                'storeTruncated' => [] !== $journal['entries'] && $journal['truncated'],
                'processTraceCount' => \count($processTf['segments']),
                'processTimeframe' => $processTf,
                'storeRows' => $rows,
                'timelineEntries' => $groupedTimeline[$eid] ?? [],
                'journalHint' => $this->buildJournalHint($rows, $storeCountLive, $timelineHasDispatch),
                'runTimeline' => $this->runTimeline($eid, $wf ?? ''),
                // Where each Nexus operation is served, and whether it is still in flight (#670).
                'nexusOperations' => array_map(static fn(NexusOperationSummary $operation): array => [
                    'endpoint' => $operation->endpoint,
                    'service' => $operation->service,
                    'operation' => $operation->operation,
                    'state' => $operation->state->value,
                    'stateLabel' => $operation->state->label(),
                ], NexusOperationSummary::of(array_column($this->journal($eid)['entries'], 'event'))),
            ];
        }

        return $out;
    }

    /**
     * The shared frieze of the run pages (#819), over the events this panel read. Only what the
     * drawing needs is kept: a `TimelineEvent` carries the event's unmasked details, and `$this->data`
     * is written to the profile.
     *
     * @return array{span: float, spanLabel: string, actions: list<array<string, mixed>>}
     */
    private function runTimeline(string $executionId, string $workflowType): array
    {
        $timeline = RunTimeline::of(
            JournalRunHistoryReader::fromEntries($this->journal($executionId)['entries'], $workflowType),
            $this->redactor,
        );

        return [
            'span' => $timeline->span,
            'spanLabel' => $timeline->spanLabel,
            'actions' => array_map(static fn(TimelineAction $action): array => [
                'kind' => $action->kind->value,
                'label' => $action->label,
                'durationLabel' => $action->durationLabel,
                'segments' => array_map(static fn(TimelineSegment $segment): array => [
                    'offset' => $segment->offset,
                    'duration' => $segment->duration,
                    'waiting' => $segment->waiting,
                    'failed' => $segment->failed,
                    'title' => $segment->title,
                ], $action->segments),
                'marks' => array_map(static fn(TimelineEvent $mark): array => [
                    'offset' => $mark->offset,
                    'failed' => $mark->event->failed,
                    'title' => $mark->title,
                ], $action->events),
            ], $timeline->actions),
        ];
    }

    /**
     * Status: journal ({@see collectStoreEventRows}), then metadata, then a re-read of the store
     * (same SQLite file / different worker process than the HTTP request).
     *
     * @param list<array<string, mixed>>                                                        $rows     rows from {@see collectStoreEventRows} for this executionId
     * @param array{workflowType: string, payload: array<string, mixed>, completed?: bool}|null $metadata
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

        if (null !== $metadata && ($metadata['completed'] ?? false) === true) {
            return 'completed';
        }

        $n = $this->journal($executionId)['count'];
        if ($n > 0) {
            $lastEvent = $this->journal($executionId)['last'];
            if (null !== $lastEvent) {
                return $this->mapEventShortNameToStatus((new \ReflectionClass($lastEvent))->getShortName());
            }
        }

        // Before the metadata branch: the metadata exists from the dispatch, so a run dispatched
        // on this request and not executed yet has it too (#851).
        if ($timelineHasDispatch && 0 === $n) {
            return 'queued';
        }

        if (null !== $metadata && ($metadata['completed'] ?? false) === false && $this->metadataStore->hasActiveWorkflowMetadata(ExecutionId::fromString($executionId))) {
            return 'running';
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

        return 'A ResumeWorkflowMessage dispatch was observed on this request, but the journal is still empty: '
            . 'the handler has most likely not run in this process yet (asynchronous Messenger). '
            . 'To fill the journal within the same profile, use the demo that waits (drain), or reload with '
            . '?durable_execution=&lt;uuid&gt; once the workers have been through.';
    }

    /**
     * @param list<array<string, mixed>> $rows rows from {@see collectStoreEventRows} for this executionId
     */
    private function inferExecutionStatusFromLastStoreRow(array $rows): string
    {
        $lastKey = array_key_last($rows);
        if (null === $lastKey) {
            return 'running';
        }
        $last = $rows[$lastKey];

        return $this->mapEventShortNameToStatus((string) ($last['type'] ?? ''));
    }

    private function mapEventShortNameToStatus(string $shortName): string
    {
        return match ($shortName) {
            'ExecutionCompleted' => 'completed',
            'WorkflowExecutionFailed' => 'failed',
            'WorkflowContinuedAsNew' => 'continued_as_new',
            'WorkflowCancellationRequested' => 'cancel_requested',
            'WorkflowExecutionCancelled' => 'cancelled',
            default => 'running',
        };
    }

    /**
     * The words of the other dashboards for the five outcomes. `cancel_requested`, `queued` and
     * `pending` are not outcomes: they say where a run is on this request, and keep their own.
     */
    private function executionStatusLabel(string $code): string
    {
        return match ($code) {
            'completed' => 'Completed',
            'failed' => 'Failed',
            'continued_as_new' => 'Continued as new',
            'cancel_requested' => 'Cancellation requested',
            'cancelled' => 'Cancelled',
            'running' => 'Running',
            'queued' => 'Queued (no journal yet)',
            'pending' => 'Pending',
            default => 'Unknown',
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
        $segments = $fullProcess['segments'];
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
            // Already a string when the barrier in collect() runs, so it redacts here.
            $j = json_encode($this->redactor->redact(RecordedDetails::storable($payload)), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return '…';
        }
        if (\strlen($j) > 140) {
            return substr($j, 0, 137) . '…';
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
                    ? 'Engine resume · ' . ('' !== $wt ? $wt : '(unknown type)')
                    : 'Engine start · ' . ('' !== $wt ? $wt : '(unknown type)');
            }
            if ('activity' === $kind) {
                $timeline[$i]['dispatchSummary'] = ($e['activityName'] ?? '?') . ' · ' . ($e['activityId'] ?? '?')
                    . (empty($e['success']) && \array_key_exists('success', $e) ? ' · failed' : '');
            }
        }

        return $timeline;
    }

    /**
     * Identifiers coming from the process trace (Messenger dispatch, engine run, activities — e.g. a worker with no workflow dispatch on this request).
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
            $n += $this->journal($eid)['count'];
        }

        return $n;
    }

    /**
     * Includes executions to display from the query (event store journal even with no dispatch observed on this request).
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

        $taken = 0;
        foreach (explode(',', $raw) as $part) {
            $id = trim($part);
            // Printable ASCII with no space, at most 255 bytes: a UUID, a Temporal workflow id,
            // a child id. Anything else no store issued, and HTML has no business in there.
            if (1 !== preg_match('/^[\x21-\x7E]{1,255}$/', $id) || 1 === preg_match('/[<>"\'&]/', $id)) {
                continue;
            }
            if (isset($executionIds[$id])) {
                continue;
            }
            $executionIds[$id] = true;
            if (++$taken >= self::MAX_QUERIED_EXECUTIONS) {
                break;
            }
        }

        return $executionIds;
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

        $starts = array_map(static fn(array $s): float => $s['startSec'], $raw);
        $ends = array_map(static fn(array $s): float => $s['endSec'], $raw);
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
            foreach ($this->journal($eid)['entries'] as $entry) {
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
                    'phase' => JournalRunHistoryReader::phaseOf($event)?->value,
                    'payload' => $this->redacted($event->payload()),
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
        usort($ordered, static fn(array $a, array $b): int => ($a['seq'] ?? 0) <=> ($b['seq'] ?? 0));

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
     * Total number of events in the journal (event store) for all the collected executionIds.
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
     *     process: array{bounds: array{tMin: float, tMax: float, spanSec: float}|null, segments: list<array<string, mixed>>}
     * }
     */
    public function getTimeFrame(): array
    {
        $tf = $this->data['time_frame'] ?? null;
        if (!\is_array($tf)) {
            return [
                'process' => ['bounds' => null, 'segments' => []],
            ];
        }
        $process = \is_array($tf['process'] ?? null) ? $tf['process'] : [];

        return [
            'process' => [
                'bounds' => $process['bounds'] ?? null,
                'segments' => \is_array($process['segments'] ?? null) ? $process['segments'] : [],
            ],
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

    private function redacted(mixed $payload): mixed
    {
        return $this->redactor->redact(RecordedDetails::storable($payload));
    }

    /**
     * The run's metadata row, its payload masked.
     *
     * @return array{workflowType: string, payload: array<string, mixed>, completed?: bool}|null
     */
    private function metadata(string $executionId): ?array
    {
        $meta = $this->metadataStore->get(ExecutionId::fromString($executionId));
        if (null !== $meta) {
            $payload = $this->redacted($meta['payload']);
            $meta['payload'] = \is_array($payload) ? $payload : [];
        }

        return $meta;
    }

    /**
     * @return array{entries: list<array{event: Event, recordedAt: ?\DateTimeImmutable}>, truncated: bool, count: int, last: ?Event}
     */
    private function journal(string $executionId): array
    {
        if (!isset($this->journals[$executionId])) {
            // An indexed COUNT, then a read that stops at the panel's limit: the SQL stores walk a
            // cursor, and breaking out of it spares hydrating the rest of a long journal.
            $count = $this->eventStore->countEventsInStream(ExecutionId::fromString($executionId));
            $entries = [];
            foreach ($this->eventStore->readStreamWithRecordedAt(ExecutionId::fromString($executionId)) as $entry) {
                if (\count($entries) >= self::MAX_STORE_EVENTS_PER_STREAM) {
                    break;
                }
                $entries[] = $entry;
            }
            $this->journals[$executionId] = [
                'entries' => $entries,
                'truncated' => $count > \count($entries),
                'count' => $count,
                // Of the events read: the status reads the last one the panel shows, as it did.
                'last' => [] === $entries ? null : $entries[\count($entries) - 1]['event'],
            ];
        }

        return $this->journals[$executionId];
    }

    #[\Override]
    public function reset(): void
    {
        $this->data = [];
    }

    /**
     * No `#[\Override]`: Symfony's DataCollector only declares `__serialize()` from 7.0 onwards,
     * and the attribute would make loading this class fail on 6.4 (PHP ≥ 8.3).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $d = $this->data;

        return \is_array($d) ? $d : [];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->data = $data;
    }
}
