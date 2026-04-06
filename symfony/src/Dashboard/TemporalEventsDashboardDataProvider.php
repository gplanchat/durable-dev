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
     *      events: list<array{eventId: int, time: string, type: string}>,
     *      workflowId?: string
     *   }>,
     *   nextCursor: string|null
     * }
     */
    public function provideRunsPage(string $cursor = '', int $pageSize = self::DEFAULT_PAGE_SIZE): array
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
            $request->setQuery('WorkflowId STARTS_WITH "durable-"');
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
     *   events: list<array{eventId: int, time: string, type: string}>,
     *   workflowId?: string
     * } $run
     *
     * @return array{
     *   runId: string,
     *   workflowName: string,
     *   status: 'running'|'completed'|'failed',
     *   taskQueue: string,
     *   startedAt: string,
     *   duration: string,
     *   events: list<array{eventId: int, time: string, type: string}>,
     *   workflowId?: string
     * }
     */
    public function enrichWithHistory(array $run): array
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
            foreach ($this->historyCursor->events($execution) as $historyEvent) {
                $tail[] = $historyEvent;
                if (\count($tail) > 30) {
                    \array_shift($tail);
                }
            }
            if ([] === $tail) {
                return $run;
            }

            $events = [];
            /** @var HistoryEvent $event */
            foreach ($tail as $event) {
                $events[] = [
                    'eventId' => (int) $event->getEventId(),
                    'time' => $this->formatProtoTimestamp($event->getEventTime()),
                    'type' => $this->normalizeEventType(EventType::name($event->getEventType())),
                ];
            }
            $run['events'] = $events;
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
     *   events: list<array{eventId: int, time: string, type: string}>,
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
     *   events: list<array{eventId: int, time: string, type: string}>,
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
                    ['eventId' => 1, 'time' => '13:25:32', 'type' => 'WORKFLOW EXECUTION STARTED'],
                    ['eventId' => 2, 'time' => '13:25:35', 'type' => 'ACTIVITY TASK SCHEDULED'],
                    ['eventId' => 3, 'time' => '13:29:44', 'type' => 'WORKFLOW TASK STARTED'],
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
                    ['eventId' => 1, 'time' => '12:58:03', 'type' => 'WORKFLOW EXECUTION STARTED'],
                    ['eventId' => 2, 'time' => '12:58:10', 'type' => 'ACTIVITY TASK COMPLETED'],
                    ['eventId' => 3, 'time' => '12:59:50', 'type' => 'WORKFLOW EXECUTION COMPLETED'],
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
                    ['eventId' => 1, 'time' => '12:40:11', 'type' => 'WORKFLOW EXECUTION STARTED'],
                    ['eventId' => 2, 'time' => '12:40:32', 'type' => 'ACTIVITY TASK FAILED'],
                    ['eventId' => 3, 'time' => '12:41:04', 'type' => 'WORKFLOW EXECUTION FAILED'],
                ],
                'workflowId' => 'durable-demo-run-003',
            ],
        ];
    }
}
