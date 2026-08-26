<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Dashboard;

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
    private const TIMELAPSE_MIN_SECONDS = 0.001; // 1 ms
    private const TIMELAPSE_MIN_BAR_PERCENT = 0.25;

    public function __construct(
        private readonly ?WorkflowServiceClient $workflowServiceClient = null,
        private readonly ?TemporalConnection $connection = null,
        private readonly ?TemporalHistoryCursor $historyCursor = null,
    ) {}

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
     *   nextCursor: string|null,
     *   temporal: array{
     *      connected: bool,
     *      namespace: string|null,
     *      message: string,
     *      checkedAt: string,
     *      lastSuccessfulAt: string|null
     *   }
     * }
     */
    public function provideRunsPage(string $cursor = '', int $pageSize = self::DEFAULT_PAGE_SIZE, string $status = 'all'): array
    {
        $checkedAt = $this->nowTimestamp();

        if (null === $this->workflowServiceClient || null === $this->connection) {
            return [
                'runs' => [],
                'nextCursor' => null,
                'temporal' => [
                    'connected' => false,
                    'namespace' => null,
                    'message' => 'Temporal client is not available in the current application container.',
                    'checkedAt' => $checkedAt,
                    'lastSuccessfulAt' => null,
                ],
            ];
        }

        try {
            $request = new ListWorkflowExecutionsRequest();
            $request->setNamespace($this->connection->namespace->name());
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
                $request = new ListWorkflowExecutionsRequest();
                $request->setNamespace($this->connection->namespace->name());
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
                    'runs' => [],
                    'nextCursor' => null,
                    'temporal' => [
                        'connected' => false,
                        'namespace' => $this->connection->namespace->name(),
                        'message' => 'Temporal is currently unreachable or did not accept the visibility request.',
                        'checkedAt' => $checkedAt,
                        'lastSuccessfulAt' => null,
                    ],
                ];
            }
        }

        if (!$response instanceof ListWorkflowExecutionsResponse) {
            return [
                'runs' => [],
                'nextCursor' => null,
                'temporal' => [
                    'connected' => false,
                    'namespace' => $this->connection->namespace->name(),
                    'message' => 'Temporal returned an unexpected response.',
                    'checkedAt' => $checkedAt,
                    'lastSuccessfulAt' => null,
                ],
            ];
        }

        $lastSuccessfulAt = $this->nowTimestamp();

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
                'runs' => [],
                'nextCursor' => null,
                'temporal' => [
                    'connected' => true,
                    'namespace' => $this->connection->namespace->name(),
                    'message' => 'Connected to Temporal. No workflow execution found for the selected filters.',
                    'checkedAt' => $checkedAt,
                    'lastSuccessfulAt' => $lastSuccessfulAt,
                ],
            ];
        }

        $nextToken = $response->getNextPageToken();

        return [
            'runs' => $runs,
            'nextCursor' => '' !== $nextToken ? $this->encodeCursor($nextToken) : null,
            'temporal' => [
                'connected' => true,
                'namespace' => $this->connection->namespace->name(),
                'message' => 'Connected to Temporal.',
                'checkedAt' => $checkedAt,
                'lastSuccessfulAt' => $lastSuccessfulAt,
            ],
        ];
    }

    private function nowTimestamp(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
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
     * @param list<string> $visibleKinds
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
            $run['timeline'] = $this->finalizeTimeline($timelineRaw, $zoom, $visibleKinds, 'running' === $run['status']);
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
        return match ($status) {
            'running' => 'ExecutionStatus = "Running"',
            'completed' => 'ExecutionStatus = "Completed"',
            'failed' => 'ExecutionStatus = "Failed" OR ExecutionStatus = "TimedOut" OR ExecutionStatus = "Canceled" OR ExecutionStatus = "Terminated"',
            default => '',
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
     *   activities: array<array-key, array{label: string, start: float, end: float, running: bool}>,
     *   signals: list<array{label: string, time: float}>,
     *   queries: list<array{label: string, time: float}>,
     *   updates: array<array-key, array{label: string, start: float, end: float, running: bool}>,
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
     *   activities: array<array-key, array{label: string, start: float, end: float, running: bool}>,
     *   signals: list<array{label: string, time: float}>,
     *   queries: list<array{label: string, time: float}>,
     *   updates: array<array-key, array{label: string, start: float, end: float, running: bool}>,
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
            $activityTypeName = null;

            if (EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED === $event->getEventType()) {
                $attributes = $event->getActivityTaskScheduledEventAttributes();
                if (null !== $attributes) {
                    $activityId = (string) $attributes->getActivityId();
                    $activityType = $attributes->getActivityType();
                    if (null !== $activityType) {
                        $candidateActivityTypeName = (string) $activityType->getName();
                        if ('' !== $candidateActivityTypeName) {
                            $activityTypeName = $candidateActivityTypeName;
                        }
                    }
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

            $key = null !== $scheduledId ? (string) $scheduledId : 'activity-' . $event->getEventId();
            if (!isset($timelineRaw['activities'][$key])) {
                $label = 'activity-' . $key;
                if (null !== $activityTypeName) {
                    $label = $activityTypeName;
                } elseif (null !== $activityId && '' !== $activityId) {
                    $label = $activityId;
                }
                $timelineRaw['activities'][$key] = [
                    'label' => $label,
                    'start' => $eventTimestamp,
                    'end' => $eventTimestamp,
                    'running' => true,
                ];
            } else {
                $timelineRaw['activities'][$key]['start'] = \min($timelineRaw['activities'][$key]['start'], $eventTimestamp);
                $timelineRaw['activities'][$key]['end'] = \max($timelineRaw['activities'][$key]['end'], $eventTimestamp);
                if (null !== $activityTypeName && ($this->isUuidLikeLabel($timelineRaw['activities'][$key]['label']) || str_starts_with($timelineRaw['activities'][$key]['label'], 'activity-'))) {
                    $timelineRaw['activities'][$key]['label'] = $activityTypeName;
                }
            }
            if (\in_array($event->getEventType(), [
                EventType::EVENT_TYPE_ACTIVITY_TASK_COMPLETED,
                EventType::EVENT_TYPE_ACTIVITY_TASK_FAILED,
                EventType::EVENT_TYPE_ACTIVITY_TASK_CANCELED,
            ], true)) {
                $timelineRaw['activities'][$key]['running'] = false;
            } elseif (\in_array($event->getEventType(), [
                EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED,
                EventType::EVENT_TYPE_ACTIVITY_TASK_STARTED,
            ], true)) {
                $timelineRaw['activities'][$key]['running'] = true;
            }

            return;
        }

        if (EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED === $event->getEventType()) {
            $attributes = $event->getWorkflowExecutionSignaledEventAttributes();
            $name = null !== $attributes ? (string) $attributes->getSignalName() : 'signal-' . $event->getEventId();
            $timelineRaw['signals'][] = ['label' => $name, 'time' => $eventTimestamp];

            return;
        }

        if (\str_contains($eventTypeName, 'QUERY_')) {
            $timelineRaw['queries'][] = [
                'label' => $this->toShortQueryLabel($eventTypeName, (int) $event->getEventId()),
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
                $timelineRaw['updateAcceptedByEventId'][(int) $event->getEventId()] = $updateKey ?? ('update-' . $event->getEventId());
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
                    if (null !== $meta) {
                        $metaLabel = (string) $meta->getUpdateId();
                        if ('' !== $metaLabel) {
                            $updateLabel = $metaLabel;
                        }
                    }
                }
            }

            if (null === $updateKey || '' === $updateKey) {
                $updateKey = 'update-' . $event->getEventId();
            }
            if (null === $updateLabel) {
                $updateLabel = $updateKey;
            }
            $updateLabel = $this->toShortUpdateLabel($updateLabel, (int) $event->getEventId());

            if (!isset($timelineRaw['updates'][$updateKey])) {
                $timelineRaw['updates'][$updateKey] = [
                    'label' => $updateLabel,
                    'start' => $eventTimestamp,
                    'end' => $eventTimestamp,
                    'running' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED === $event->getEventType(),
                ];
            } else {
                $timelineRaw['updates'][$updateKey]['start'] = \min($timelineRaw['updates'][$updateKey]['start'], $eventTimestamp);
                $timelineRaw['updates'][$updateKey]['end'] = \max($timelineRaw['updates'][$updateKey]['end'], $eventTimestamp);
                if (str_starts_with($timelineRaw['updates'][$updateKey]['label'], 'update-') && !str_starts_with($updateLabel, 'update-')) {
                    $timelineRaw['updates'][$updateKey]['label'] = $updateLabel;
                }
                if (EventType::EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_COMPLETED === $event->getEventType()) {
                    $timelineRaw['updates'][$updateKey]['running'] = false;
                }
            }
        }
    }

    /**
     * @param array{
     *   min: float|null,
     *   max: float|null,
     *   activities: array<array-key, array{label: string, start: float, end: float, running: bool}>,
     *   signals: list<array{label: string, time: float}>,
     *   queries: list<array{label: string, time: float}>,
     *   updates: array<array-key, array{label: string, start: float, end: float, running: bool}>,
     *   updateAcceptedByEventId: array<int, string>
     * } $timelineRaw
     * @param list<string> $visibleKinds
     *
     * @return array{
     *   startTime: string,
     *   endTime: string,
     *   zoom: string,
     *   windowDurationLabel: string,
     *   lanes: list<array{
     *      label: string,
     *      kind: string,
     *      startPercent: float,
     *      widthPercent: float,
     *      isRunning: bool,
     *      startTime: string,
     *      endTime: string
     *   }>
     * }
     */
    private function finalizeTimeline(array $timelineRaw, string $zoom, array $visibleKinds, bool $executionRunning = false): array
    {
        $min = $timelineRaw['min'];
        $max = $timelineRaw['max'];
        if (null === $min || null === $max) {
            $now = (float) \time();

            return [
                'startTime' => $this->formatFromFloatSeconds($now),
                'endTime' => $this->formatFromFloatSeconds($now),
                'zoom' => $zoom,
                'windowDurationLabel' => '0s',
                'lanes' => [],
            ];
        }

        if ($max <= $min) {
            $max = $min + self::TIMELAPSE_MIN_SECONDS;
        }

        [$viewMin, $viewMax] = $this->resolveZoomWindow($min, $max, $zoom);
        $visible = \array_fill_keys($visibleKinds, true);

        $lanes = [];
        if (isset($visible['execution'])) {
            $executionLane = $this->buildLaneWithinViewport('execution', 'Execution', $min, $max, $viewMin, $viewMax, $executionRunning);
            if (null !== $executionLane) {
                $lanes[] = $executionLane;
            }
        }

        if (isset($visible['activity'])) {
            foreach ($timelineRaw['activities'] as $activity) {
                $lane = $this->buildLaneWithinViewport('activity', 'Activity: ' . $activity['label'], $activity['start'], $activity['end'], $viewMin, $viewMax, $activity['running']);
                if (null !== $lane) {
                    $lanes[] = $lane;
                }
            }
        }

        if (isset($visible['signal'])) {
            foreach ($timelineRaw['signals'] as $signal) {
                $lane = $this->buildPointLaneWithinViewport('signal', 'Signal: ' . $signal['label'], $signal['time'], $viewMin, $viewMax, false);
                if (null !== $lane) {
                    $lanes[] = $lane;
                }
            }
        }

        if (isset($visible['query'])) {
            foreach ($timelineRaw['queries'] as $query) {
                $lane = $this->buildPointLaneWithinViewport('query', 'Query: ' . $query['label'], $query['time'], $viewMin, $viewMax, false);
                if (null !== $lane) {
                    $lanes[] = $lane;
                }
            }
        }

        if (isset($visible['update'])) {
            foreach ($timelineRaw['updates'] as $update) {
                $lane = $this->buildLaneWithinViewport('update', 'Update: ' . $update['label'], $update['start'], $update['end'], $viewMin, $viewMax, $update['running']);
                if (null !== $lane) {
                    $lanes[] = $lane;
                }
            }
        }

        return [
            'startTime' => $this->formatFromFloatSeconds($viewMin),
            'endTime' => $this->formatFromFloatSeconds($viewMax),
            'zoom' => $zoom,
            'windowDurationLabel' => $this->formatWindowDurationLabel($viewMax - $viewMin),
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
        $zoomMax = \max($zoomMin + self::TIMELAPSE_MIN_SECONDS, $maxTime);

        return [$zoomMin, $zoomMax];
    }

    /**
     * @return array{
     *   label: string,
     *   kind: string,
     *   startPercent: float,
     *   widthPercent: float,
     *   isRunning: bool,
     *   startTime: string,
     *   endTime: string
     * }
     */
    private function buildLane(string $kind, string $label, float $start, float $end, float $globalMin, float $globalMax, bool $isRunning): array
    {
        $range = \max(self::TIMELAPSE_MIN_SECONDS, $globalMax - $globalMin);
        $startPercent = (($start - $globalMin) / $range) * 100.0;
        $widthPercent = \max(self::TIMELAPSE_MIN_BAR_PERCENT, (($end - $start) / $range) * 100.0);
        if ($startPercent + $widthPercent > 100.0) {
            $widthPercent = \max(self::TIMELAPSE_MIN_BAR_PERCENT, 100.0 - $startPercent);
        }

        return [
            'label' => $label,
            'kind' => $kind,
            'startPercent' => \round($startPercent, 3),
            'widthPercent' => \round($widthPercent, 3),
            'isRunning' => $isRunning,
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
     *   isRunning: bool,
     *   startTime: string,
     *   endTime: string
     * }|null
     */
    private function buildLaneWithinViewport(string $kind, string $label, float $start, float $end, float $viewMin, float $viewMax, bool $isRunning): ?array
    {
        if ($end < $viewMin || $start > $viewMax) {
            return null;
        }

        $clippedStart = \max($start, $viewMin);
        $clippedEnd = \min($end, $viewMax);

        return $this->buildLane($kind, $label, $clippedStart, $clippedEnd, $viewMin, $viewMax, $isRunning);
    }

    /**
     * @return array{
     *   label: string,
     *   kind: string,
     *   startPercent: float,
     *   widthPercent: float,
     *   isRunning: bool,
     *   startTime: string,
     *   endTime: string
     * }|null
     */
    private function buildPointLaneWithinViewport(string $kind, string $label, float $time, float $globalMin, float $globalMax, bool $isRunning): ?array
    {
        return $this->buildLaneWithinViewport($kind, $label, $time, $time, $globalMin, $globalMax, $isRunning);
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
        $microseconds = (int) \round(($seconds - (float) $wholeSeconds) * 1_000_000.0);
        $value = \sprintf('%d.%06d', $wholeSeconds, \max(0, \min(999999, $microseconds)));
        $date = \DateTimeImmutable::createFromFormat('U.u', $value, new \DateTimeZone('UTC'));

        if (false === $date) {
            return '--:--:--';
        }

        return $date->setTimezone(new \DateTimeZone(\date_default_timezone_get()))->format('H:i:s.v');
    }

    private function formatWindowDurationLabel(float $seconds): string
    {
        $seconds = \max(0.0, $seconds);
        if ($seconds < 1.0) {
            return \sprintf('%dms', (int) \round($seconds * 1000.0));
        }

        $total = (int) \round($seconds);
        $hours = (int) \floor($total / 3600);
        $minutes = (int) \floor(($total % 3600) / 60);
        $remaining = $total % 60;

        if ($hours > 0) {
            return \sprintf('%dh %02dm %02ds', $hours, $minutes, $remaining);
        }
        if ($minutes > 0) {
            return \sprintf('%dm %02ds', $minutes, $remaining);
        }

        return \sprintf('%ds', $remaining);
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

    private function isUuidLikeLabel(string $label): bool
    {
        return 1 === \preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $label);
    }

    private function toShortQueryLabel(string $eventTypeName, int $eventId): string
    {
        $raw = \str_replace('EVENT_TYPE_', '', $eventTypeName);
        $raw = \str_replace('WORKFLOW_EXECUTION_', '', $raw);

        if (\str_starts_with($raw, 'QUERY_')) {
            $raw = \substr($raw, \strlen('QUERY_'));
        }

        $raw = \trim($raw);
        if ('' === $raw) {
            return 'query-' . $eventId;
        }

        $normalized = \ucwords(\strtolower(\str_replace('_', ' ', $raw)));

        return 'Query: ' . $normalized;
    }

    private function toShortUpdateLabel(string $label, int $eventId): string
    {
        $label = \trim($label);
        if ('' === $label) {
            return 'update-' . $eventId;
        }

        if (\str_starts_with($label, 'EVENT_TYPE_')) {
            $normalized = $this->normalizeEventType($label);

            return '' === $normalized ? 'update-' . $eventId : $normalized;
        }

        return $label;
    }

}
