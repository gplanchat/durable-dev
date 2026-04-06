<?php

declare(strict_types=1);

namespace App\Dashboard;

use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalGrpcTimeouts;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\WorkflowExecutionStatus;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\Workflow\V1\WorkflowExecutionInfo;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsRequest;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

final class TemporalEventsDashboardDataProvider
{
    private const DEFAULT_PAGE_SIZE = 20;

    public function __construct(
        private readonly ?WorkflowServiceClient $workflowServiceClient = null,
        private readonly ?TemporalConnection $connection = null,
        private readonly ?TemporalHistoryCursor $historyCursor = null,
    ) {
    }

    /**
     * @return array{
     *   runs: list<array{
     *      runId: string,
     *      workflowName: string,
     *      status: 'running'|'completed'|'failed',
     *      taskQueue: string,
     *      startedAt: string,
     *      duration: string,
     *      events: list<array{eventId: int, time: string, type: string, category: string}>,
     *      workflowId?: string
     *   }>,
     *   nextCursor: string|null
     * }
     */
    public function provideRunsPage(string $cursor = '', int $pageSize = self::DEFAULT_PAGE_SIZE, string $status = 'all'): array
    {
        if (null === $this->workflowServiceClient || null === $this->connection) {
            return [
                'runs' => $this->fallbackRuns(),
                'nextCursor' => null,
            ];
        }

        try {
            $request = new ListWorkflowExecutionsRequest();
            $request->setNamespace($this->connection->namespace);
            $request->setPageSize($pageSize);
            $request->setQuery($this->buildVisibilityQuery($status));
            if ('' !== $cursor) {
                $request->setNextPageToken($this->decodeCursor($cursor));
            }

            $response = GrpcUnary::wait(
                $this->workflowServiceClient->ListWorkflowExecutions(
                    $request,
                    [],
                    ['timeout' => TemporalGrpcTimeouts::SHORT_US],
                ),
            );
        } catch (\Throwable) {
            try {
                // Some Temporal deployments do not support custom visibility query syntax.
                $request = new ListWorkflowExecutionsRequest();
                $request->setNamespace($this->connection->namespace);
                $request->setPageSize($pageSize);
                if ('' !== $cursor) {
                    $request->setNextPageToken($this->decodeCursor($cursor));
                }

                $response = GrpcUnary::wait(
                    $this->workflowServiceClient->ListWorkflowExecutions(
                        $request,
                        [],
                        ['timeout' => TemporalGrpcTimeouts::SHORT_US],
                    ),
                );
            } catch (\Throwable) {
                return [
                    'runs' => $this->fallbackRuns(),
                    'nextCursor' => null,
                ];
            }
        }

        if (!$response instanceof ListWorkflowExecutionsResponse) {
            return [
                'runs' => $this->fallbackRuns(),
                'nextCursor' => null,
            ];
        }

        $runs = [];
        foreach ($response->getExecutions() as $info) {
            $run = $this->fromExecutionInfo($info);
            if (null !== $run) {
                $runs[] = $run;
            }
        }

        \usort($runs, static function (array $left, array $right): int {
            return \strcmp($right['startedAt'], $left['startedAt']);
        });

        if ([] === $runs) {
            return [
                'runs' => $this->fallbackRuns(),
                'nextCursor' => null,
            ];
        }

        $nextToken = $response->getNextPageToken();

        return [
            'runs' => $runs,
            'nextCursor' => '' !== $nextToken ? $this->encodeCursor($nextToken) : null,
        ];
    }

    /**
     * @param array{
     *   runId: string,
     *   workflowName: string,
     *   status: 'running'|'completed'|'failed',
     *   taskQueue: string,
     *   startedAt: string,
     *   duration: string,
     *   events: list<array{eventId: int, time: string, type: string, category: string}>,
     *   workflowId?: string,
     *   timeline?: array<string, mixed>
     * } $run
     *
     * @return array{
     *   runId: string,
     *   workflowName: string,
     *   status: 'running'|'completed'|'failed',
     *   taskQueue: string,
     *   startedAt: string,
     *   duration: string,
     *   events: list<array{eventId: int, time: string, type: string, category: string}>,
     *   workflowId?: string,
     *   timeline?: array<string, mixed>
     * }
     */
    /**
     * @param list<string> $visibleKinds
     */
    public function enrichWithHistory(array $run, string $zoom = 'all', array $visibleKinds = ['execution', 'activity', 'signal', 'query', 'update']): array
    {
        if (null === $this->historyCursor) {
            return $run;
        }

        $workflowId = (string) ($run['workflowId'] ?? '');
        $runId = $run['runId'];
        if ('' === $workflowId || '' === $runId) {
            return $run;
        }

        try {
            $execution = new WorkflowExecution();
            $execution->setWorkflowId($workflowId);
            $execution->setRunId($runId);

            $tail = [];
            $timelineRaw = $this->initTimelineRaw();
            foreach ($this->historyCursor->events($execution) as $historyEvent) {
                $tail[] = $historyEvent;
                if (\count($tail) > 30) {
                    \array_shift($tail);
                }

                $eventTypeName = EventType::name($historyEvent->getEventType());
                $eventTime = $historyEvent->getEventTime();
                $eventTimestamp = $this->timestampToFloat($eventTime);
                if (null !== $eventTimestamp) {
                    $timelineRaw['min'] = null === $timelineRaw['min'] ? $eventTimestamp : \min($timelineRaw['min'], $eventTimestamp);
                    $timelineRaw['max'] = null === $timelineRaw['max'] ? $eventTimestamp : \max($timelineRaw['max'], $eventTimestamp);
                }

                $this->collectTimelineEvent($timelineRaw, $historyEvent, $eventTypeName, $eventTimestamp);
            }
            if ([] === $tail) {
                return $run;
            }

            $events = [];
            /** @var HistoryEvent $event */
            foreach ($tail as $event) {
                $rawEventType = EventType::name($event->getEventType());
                $events[] = [
                    'eventId' => (int) $event->getEventId(),
                    'time' => $this->formatProtoTimestamp($event->getEventTime()),
                    'type' => $this->normalizeEventType($rawEventType),
                    'category' => $this->categoryForEventType($rawEventType),
                ];
            }
            $run['events'] = $events;
            $run['timeline'] = $this->finalizeTimeline($timelineRaw, $zoom, $visibleKinds);
        } catch (\Throwable) {
            // Keep run without history preview when Temporal cannot return history.
        }

        return $run;
    }

    /**
     * @return array{
     *   runId: string,
     *   workflowName: string,
     *   status: 'running'|'completed'|'failed',
     *   taskQueue: string,
     *   startedAt: string,
     *   duration: string,
     *   events: list<array{eventId: int, time: string, type: string, category: string}>,
     *   workflowId?: string,
     * }|null
     */
    private function fromExecutionInfo(WorkflowExecutionInfo $info): ?array
    {
        $execution = $info->getExecution();
        if (null === $execution) {
            return null;
        }
        $workflowType = $info->getType();

        $workflowId = (string) $execution->getWorkflowId();
        $runId = (string) $execution->getRunId();
        if ('' === $runId) {
            return null;
        }

        $status = $this->mapStatus($info->getStatus());
        return [
            'runId' => $runId,
            'workflowName' => null !== $workflowType ? (string) $workflowType->getName() : 'UnknownWorkflow',
            'status' => $status,
            'taskQueue' => (string) $info->getTaskQueue(),
            'startedAt' => $this->formatProtoTimestamp($info->getStartTime(), 'Y-m-d H:i:s'),
            'duration' => $this->formatProtoDuration($info->getExecutionDuration(), $info->getStartTime(), $info->getCloseTime()),
            'events' => [],
            'workflowId' => $workflowId,
        ];
    }

    private function mapStatus(int $status): string
    {
        return match ($status) {
            WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING => 'running',
            WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_COMPLETED => 'completed',
            default => 'failed',
        };
    }

    private function formatProtoTimestamp(?\Google\Protobuf\Timestamp $timestamp, string $format = 'H:i:s'): string
    {
        if (null === $timestamp) {
            return '--:--:--';
        }

        $seconds = (int) $timestamp->getSeconds();
        $micros = (int) \floor(((int) $timestamp->getNanos()) / 1000);
        $value = \sprintf('%d.%06d', $seconds, $micros);
        $date = \DateTimeImmutable::createFromFormat('U.u', $value, new \DateTimeZone('UTC'));

        return false === $date ? '--:--:--' : $date->setTimezone(new \DateTimeZone(\date_default_timezone_get()))->format($format);
    }

    private function formatProtoDuration(
        ?\Google\Protobuf\Duration $duration,
        ?\Google\Protobuf\Timestamp $startedAt,
        ?\Google\Protobuf\Timestamp $closedAt,
    ): string {
        if (null !== $duration) {
            $seconds = \max(0, (int) $duration->getSeconds());
            return $this->formatSeconds($seconds);
        }

        if (null === $startedAt) {
            return '00:00:00';
        }

        $startSeconds = (int) $startedAt->getSeconds();
        $endSeconds = null !== $closedAt ? (int) $closedAt->getSeconds() : \time();
        return $this->formatSeconds(\max(0, $endSeconds - $startSeconds));
    }

    private function formatSeconds(int $seconds): string
    {
        $hours = (int) \floor($seconds / 3600);
        $minutes = (int) \floor(($seconds % 3600) / 60);
        $remaining = $seconds % 60;

        return \sprintf('%02d:%02d:%02d', $hours, $minutes, $remaining);
    }

    private function normalizeEventType(string $eventType): string
    {
        $normalized = \str_replace('EVENT_TYPE_', '', $eventType);
        return \str_replace('_', ' ', $normalized);
    }

    private function buildVisibilityQuery(string $status): string
    {
        $baseQuery = 'WorkflowId STARTS_WITH "durable-"';

        return match ($status) {
            'running' => $baseQuery.' AND ExecutionStatus = "Running"',
            'completed' => $baseQuery.' AND ExecutionStatus = "Completed"',
            'failed' => $baseQuery.' AND (ExecutionStatus = "Failed" OR ExecutionStatus = "TimedOut" OR ExecutionStatus = "Canceled" OR ExecutionStatus = "Terminated")',
            default => $baseQuery,
        };
    }

    private function categoryForEventType(string $eventType): string
    {
        if (\str_contains($eventType, 'UPDATE_')) {
            return 'update';
        }
        if (\str_contains($eventType, 'QUERY_')) {
            return 'query';
        }
        if (\str_contains($eventType, 'WORKFLOW_')) {
            return 'workflow';
        }
        if (\str_contains($eventType, 'ACTIVITY_')) {
            return 'activity';
        }
        if (\str_contains($eventType, 'TIMER_')) {
            return 'timer';
        }
        if (\str_contains($eventType, 'SIGNAL')) {
            return 'signal';
        }
        if (\str_contains($eventType, 'CHILD_WORKFLOW')) {
            return 'child';
        }
        if (\str_contains($eventType, 'MARKER')) {
            return 'marker';
        }

        return 'other';
    }

    /**
     * @return array{
     *   min: float|null,
     *   max: float|null,
     *   activities: array<string, array{label: string, start: float, end: float}>,
     *   signals: list<array{label: string, time: float}>,
     *   queries: list<array{label: string, time: float}>,
     *   updates: array<string, array{label: string, start: float, end: float}>,
     *   updateAcceptedByEventId: array<int, string>
     * }
     */
    private function initTimelineRaw(): array
    {
        return [
            'min' => null,
            'max' => null,
            'activities' => [],
            'signals' => [],
            'queries' => [],
            'updates' => [],
            'updateAcceptedByEventId' => [],
        ];
    }

    /**
     * @param array{
     *   min: float|null,
     *   max: float|null,
     *   activities: array<string, array{label: string, start: float, end: float}>,
     *   signals: list<array{label: string, time: float}>,
     *   queries: list<array{label: string, time: float}>,
     *   updates: array<string, array{label: string, start: float, end: float}>,
     *   updateAcceptedByEventId: array<int, string>
     * } $timelineRaw
     */
    private function collectTimelineEvent(array &$timelineRaw, HistoryEvent $event, string $eventTypeName, ?float $eventTimestamp): void
    {
        if (null === $eventTimestamp) {
            return;
        }

        if (\str_contains($eventTypeName, 'ACTIVITY_TASK_')) {
            $scheduledId = null;
            $activityId = null;

            if (EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED === $event->getEventType()) {
                $attributes = $event->getActivityTaskScheduledEventAttributes();
                if (null !== $attributes) {
                    $activityId = (string) $attributes->getActivityId();
                }
                $scheduledId = (int) $event->getEventId();
            } elseif (EventType::EVENT_TYPE_ACTIVITY_TASK_STARTED === $event->getEventType()) {
                $attributes = $event->getActivityTaskStartedEventAttributes();
                if (null !== $attributes) {
                    $scheduledId = (int) $attributes->getScheduledEventId();
                }
            } elseif (EventType::EVENT_TYPE_ACTIVITY_TASK_COMPLETED === $event->getEventType()) {
                $attributes = $event->getActivityTaskCompletedEventAttributes();
                if (null !== $attributes) {
                    $scheduledId = (int) $attributes->getScheduledEventId();
                }
            } elseif (EventType::EVENT_TYPE_ACTIVITY_TASK_FAILED === $event->getEventType()) {
                $attributes = $event->getActivityTaskFailedEventAttributes();
                if (null !== $attributes) {
                    $scheduledId = (int) $attributes->getScheduledEventId();
                }
            } elseif (EventType::EVENT_TYPE_ACTIVITY_TASK_CANCELED === $event->getEventType()) {
                $attributes = $event->getActivityTaskCanceledEventAttributes();
                if (null !== $attributes) {
                    $scheduledId = (int) $attributes->getScheduledEventId();
                }
            }

            $key = null !== $scheduledId ? (string) $scheduledId : 'activity-'.$event->getEventId();
            if (!isset($timelineRaw['activities'][$key])) {
                $label = null !== $activityId && '' !== $activityId ? $activityId : 'activity-'.$key;
                $timelineRaw['activities'][$key] = [
                    'label' => $label,
                    'start' => $eventTimestamp,
                    'end' => $eventTimestamp,
                ];
            } else {
                $timelineRaw['activities'][$key]['start'] = \min($timelineRaw['activities'][$key]['start'], $eventTimestamp);
                $timelineRaw['activities'][$key]['end'] = \max($timelineRaw['activities'][$key]['end'], $eventTimestamp);
            }

            return;
        }

        if (EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED === $event->getEventType()) {
            $attributes = $event->getWorkflowExecutionSignaledEventAttributes();
            $name = null !== $attributes ? (string) $attributes->getSignalName() : 'signal-'.$event->getEventId();
            $timelineRaw['signals'][] = ['label' => $name, 'time' => $eventTimestamp];

            return;
        }

        if (\str_contains($eventTypeName, 'QUERY_')) {
            $timelineRaw['queries'][] = [
                'label' => 'query-'.$event->getEventId(),
                'time' => $eventTimestamp,
            ];

            return;
        }

        if (\str_contains($eventTypeName, 'UPDATE_')) {
            $updateKey = null;
            $updateLabel = null;

            if (EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED === $event->getEventType()) {
                $attributes = $event->getWorkflowExecutionUpdateAcceptedEventAttributes();
                if (null !== $attributes) {
                    $protocolInstanceId = (string) $attributes->getProtocolInstanceId();
                    if ('' !== $protocolInstanceId) {
                        $updateKey = $protocolInstanceId;
                    }

                    $acceptedRequest = $attributes->getAcceptedRequest();
                    if (null !== $acceptedRequest) {
                        $input = $acceptedRequest->getInput();
                        if (null !== $input) {
                            $name = (string) $input->getName();
                            if ('' !== $name) {
                                $updateLabel = $name;
                            }
                        }
                    }
                }
                $timelineRaw['updateAcceptedByEventId'][(int) $event->getEventId()] = $updateKey ?? ('update-'.$event->getEventId());
            }

            if (EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_COMPLETED === $event->getEventType()) {
                $attributes = $event->getWorkflowExecutionUpdateCompletedEventAttributes();
                if (null !== $attributes) {
                    $acceptedEventId = (int) $attributes->getAcceptedEventId();
                    $mapped = $timelineRaw['updateAcceptedByEventId'][$acceptedEventId] ?? null;
                    if (null !== $mapped) {
                        $updateKey = $mapped;
                    }
                    $meta = $attributes->getMeta();
                    if (null !== $meta && '' === (string) $updateLabel) {
                        $metaLabel = (string) $meta->getUpdateId();
                        if ('' !== $metaLabel) {
                            $updateLabel = $metaLabel;
                        }
                    }
                }
            }

            if (null === $updateKey || '' === $updateKey) {
                $updateKey = 'update-'.$event->getEventId();
            }
            if (null === $updateLabel || '' === $updateLabel) {
                $updateLabel = $updateKey;
            }

            if (!isset($timelineRaw['updates'][$updateKey])) {
                $timelineRaw['updates'][$updateKey] = [
                    'label' => $updateLabel,
                    'start' => $eventTimestamp,
                    'end' => $eventTimestamp,
                ];
            } else {
                $timelineRaw['updates'][$updateKey]['start'] = \min($timelineRaw['updates'][$updateKey]['start'], $eventTimestamp);
                $timelineRaw['updates'][$updateKey]['end'] = \max($timelineRaw['updates'][$updateKey]['end'], $eventTimestamp);
                if (str_starts_with($timelineRaw['updates'][$updateKey]['label'], 'update-') && !str_starts_with($updateLabel, 'update-')) {
                    $timelineRaw['updates'][$updateKey]['label'] = $updateLabel;
                }
            }
        }
    }

    /**
     * @param array{
     *   min: float|null,
     *   max: float|null,
     *   activities: array<string, array{label: string, start: float, end: float}>,
     *   signals: list<array{label: string, time: float}>,
     *   queries: list<array{label: string, time: float}>,
     *   updates: array<string, array{label: string, start: float, end: float}>,
     *   updateAcceptedByEventId: array<int, string>
     * } $timelineRaw
     * @param list<string> $visibleKinds
     *
     * @return array{
     *   startTime: string,
     *   endTime: string,
     *   zoom: string,
     *   lanes: list<array{
     *      label: string,
     *      kind: string,
     *      startPercent: float,
     *      widthPercent: float,
     *      startTime: string,
     *      endTime: string
     *   }>
     * }
     */
    private function finalizeTimeline(array $timelineRaw, string $zoom, array $visibleKinds): array
    {
        $min = $timelineRaw['min'];
        $max = $timelineRaw['max'];
        if (null === $min || null === $max) {
            $now = (float) \time();

            return [
                'startTime' => $this->formatFromFloatSeconds($now),
                'endTime' => $this->formatFromFloatSeconds($now),
                'zoom' => $zoom,
                'lanes' => [],
            ];
        }

        if ($max <= $min) {
            $max = $min + 1.0;
        }

        [$viewMin, $viewMax] = $this->resolveZoomWindow($min, $max, $zoom);
        $visible = \array_fill_keys($visibleKinds, true);

        $lanes = [];
        if (isset($visible['execution'])) {
            $executionLane = $this->buildLaneWithinViewport('execution', 'Execution', $min, $max, $viewMin, $viewMax);
            if (null !== $executionLane) {
                $lanes[] = $executionLane;
            }
        }

        if (isset($visible['activity'])) {
            foreach ($timelineRaw['activities'] as $activity) {
                $lane = $this->buildLaneWithinViewport('activity', 'Activity: '.$activity['label'], $activity['start'], $activity['end'], $viewMin, $viewMax);
                if (null !== $lane) {
                    $lanes[] = $lane;
                }
            }
        }

        if (isset($visible['signal'])) {
            foreach ($timelineRaw['signals'] as $signal) {
                $lane = $this->buildPointLaneWithinViewport('signal', 'Signal: '.$signal['label'], $signal['time'], $viewMin, $viewMax);
                if (null !== $lane) {
                    $lanes[] = $lane;
                }
            }
        }

        if (isset($visible['query'])) {
            foreach ($timelineRaw['queries'] as $query) {
                $lane = $this->buildPointLaneWithinViewport('query', 'Query: '.$query['label'], $query['time'], $viewMin, $viewMax);
                if (null !== $lane) {
                    $lanes[] = $lane;
                }
            }
        }

        if (isset($visible['update'])) {
            foreach ($timelineRaw['updates'] as $update) {
                $lane = $this->buildLaneWithinViewport('update', 'Update: '.$update['label'], $update['start'], $update['end'], $viewMin, $viewMax);
                if (null !== $lane) {
                    $lanes[] = $lane;
                }
            }
        }

        return [
            'startTime' => $this->formatFromFloatSeconds($viewMin),
            'endTime' => $this->formatFromFloatSeconds($viewMax),
            'zoom' => $zoom,
            'lanes' => $lanes,
        ];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function resolveZoomWindow(float $min, float $max, string $zoom): array
    {
        $maxTime = $max;
        $windowSeconds = match ($zoom) {
            '1m' => 60.0,
            '5m' => 300.0,
            '15m' => 900.0,
            default => null,
        };

        if (null === $windowSeconds) {
            return [$min, $max];
        }

        $zoomMin = \max($min, $maxTime - $windowSeconds);
        $zoomMax = \max($zoomMin + 1.0, $maxTime);

        return [$zoomMin, $zoomMax];
    }

    /**
     * @return array{
     *   label: string,
     *   kind: string,
     *   startPercent: float,
     *   widthPercent: float,
     *   startTime: string,
     *   endTime: string
     * }
     */
    private function buildLane(string $kind, string $label, float $start, float $end, float $globalMin, float $globalMax): array
    {
        $range = \max(1.0, $globalMax - $globalMin);
        $startPercent = (($start - $globalMin) / $range) * 100.0;
        $widthPercent = \max(1.2, (($end - $start) / $range) * 100.0);
        if ($startPercent + $widthPercent > 100.0) {
            $widthPercent = \max(1.2, 100.0 - $startPercent);
        }

        return [
            'label' => $label,
            'kind' => $kind,
            'startPercent' => \round($startPercent, 3),
            'widthPercent' => \round($widthPercent, 3),
            'startTime' => $this->formatFromFloatSeconds($start),
            'endTime' => $this->formatFromFloatSeconds($end),
        ];
    }

    /**
     * @return array{
     *   label: string,
     *   kind: string,
     *   startPercent: float,
     *   widthPercent: float,
     *   startTime: string,
     *   endTime: string
     * }|null
     */
    private function buildLaneWithinViewport(string $kind, string $label, float $start, float $end, float $viewMin, float $viewMax): ?array
    {
        if ($end < $viewMin || $start > $viewMax) {
            return null;
        }

        $clippedStart = \max($start, $viewMin);
        $clippedEnd = \min($end, $viewMax);

        return $this->buildLane($kind, $label, $clippedStart, $clippedEnd, $viewMin, $viewMax);
    }

    /**
     * @return array{
     *   label: string,
     *   kind: string,
     *   startPercent: float,
     *   widthPercent: float,
     *   startTime: string,
     *   endTime: string
     * }|null
     */
    private function buildPointLaneWithinViewport(string $kind, string $label, float $time, float $globalMin, float $globalMax): ?array
    {
        return $this->buildLaneWithinViewport($kind, $label, $time, $time, $globalMin, $globalMax);
    }

    private function timestampToFloat(?\Google\Protobuf\Timestamp $timestamp): ?float
    {
        if (null === $timestamp) {
            return null;
        }

        return (float) $timestamp->getSeconds() + ((float) $timestamp->getNanos() / 1_000_000_000.0);
    }

    private function formatFromFloatSeconds(float $seconds): string
    {
        $wholeSeconds = (int) \floor($seconds);
        $microseconds = (int) \round(($seconds - $wholeSeconds) * 1_000_000);
        $value = \sprintf('%d.%06d', $wholeSeconds, \max(0, \min(999999, $microseconds)));
        $date = \DateTimeImmutable::createFromFormat('U.u', $value, new \DateTimeZone('UTC'));

        if (false === $date) {
            return '--:--:--';
        }

        return $date->setTimezone(new \DateTimeZone(\date_default_timezone_get()))->format('H:i:s');
    }

    private function encodeCursor(string $token): string
    {
        return \rtrim(\strtr(\base64_encode($token), '+/', '-_'), '=');
    }

    private function decodeCursor(string $encodedCursor): string
    {
        $normalized = \strtr($encodedCursor, '-_', '+/');
        $pad = \strlen($normalized) % 4;
        if (0 !== $pad) {
            $normalized .= \str_repeat('=', 4 - $pad);
        }

        $decoded = \base64_decode($normalized, true);

        return false === $decoded ? '' : $decoded;
    }

    /**
     * @return list<array{
     *   runId: string,
     *   workflowName: string,
     *   status: 'running'|'completed'|'failed',
     *   taskQueue: string,
     *   startedAt: string,
     *   duration: string,
     *   events: list<array{eventId: int, time: string, type: string, category: string}>,
     *   workflowId?: string,
     * }>
     */
    private function fallbackRuns(): array
    {
        return [
            [
                'runId' => 'demo-run-001', // Fallback shown only when Temporal is unavailable.
                'workflowName' => 'OrderFulfillment',
                'status' => 'running',
                'taskQueue' => 'default',
                'startedAt' => '2026-04-06 13:25:32',
                'duration' => '00:04:12',
                'events' => [
                    ['eventId' => 1, 'time' => '13:25:32', 'type' => 'WORKFLOW EXECUTION STARTED', 'category' => 'workflow'],
                    ['eventId' => 2, 'time' => '13:25:35', 'type' => 'ACTIVITY TASK SCHEDULED', 'category' => 'activity'],
                    ['eventId' => 3, 'time' => '13:29:44', 'type' => 'WORKFLOW TASK STARTED', 'category' => 'workflow'],
                ],
                'workflowId' => 'durable-demo-run-001',
            ],
            [
                'runId' => 'demo-run-002',
                'workflowName' => 'InvoicePipeline',
                'status' => 'completed',
                'taskQueue' => 'default',
                'startedAt' => '2026-04-06 12:58:03',
                'duration' => '00:01:47',
                'events' => [
                    ['eventId' => 1, 'time' => '12:58:03', 'type' => 'WORKFLOW EXECUTION STARTED', 'category' => 'workflow'],
                    ['eventId' => 2, 'time' => '12:58:10', 'type' => 'ACTIVITY TASK COMPLETED', 'category' => 'activity'],
                    ['eventId' => 3, 'time' => '12:59:50', 'type' => 'WORKFLOW EXECUTION COMPLETED', 'category' => 'workflow'],
                ],
                'workflowId' => 'durable-demo-run-002',
            ],
            [
                'runId' => 'demo-run-003',
                'workflowName' => 'BookingSaga',
                'status' => 'failed',
                'taskQueue' => 'payments',
                'startedAt' => '2026-04-06 12:40:11',
                'duration' => '00:00:53',
                'events' => [
                    ['eventId' => 1, 'time' => '12:40:11', 'type' => 'WORKFLOW EXECUTION STARTED', 'category' => 'workflow'],
                    ['eventId' => 2, 'time' => '12:40:32', 'type' => 'ACTIVITY TASK FAILED', 'category' => 'activity'],
                    ['eventId' => 3, 'time' => '12:41:04', 'type' => 'WORKFLOW EXECUTION FAILED', 'category' => 'workflow'],
                ],
                'workflowId' => 'durable-demo-run-003',
            ],
        ];
    }
}
