<?php

declare(strict_types=1);

namespace App\Dashboard;

use Symfony\Component\HttpKernel\KernelInterface;

final class TemporalEventsDashboardDataProvider
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
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
    public function provideRuns(): array
    {
        $runs = [];
        $files = \glob($this->kernel->getProjectDir().'/*_events.json');
        if (false !== $files) {
            foreach ($files as $file) {
                $run = $this->parseRunFile($file);
                if (null !== $run) {
                    $runs[] = $run;
                }
            }
        }

        if ([] === $runs) {
            return $this->fallbackRuns();
        }

        \usort($runs, static function (array $left, array $right): int {
            return \strcmp($right['startedAt'], $left['startedAt']);
        });

        return $runs;
    }

    /**
     * @return array{
     *   runId: string,
     *   workflowName: string,
     *   status: 'running'|'completed'|'failed',
     *   taskQueue: string,
     *   startedAt: string,
     *   duration: string,
     *   events: list<array{time: string, type: string}>
     * }|null
     */
    private function parseRunFile(string $file): ?array
    {
        $raw = \file_get_contents($file);
        if (false === $raw) {
            return null;
        }

        try {
            /** @var array{events?: list<array<string, mixed>>} $decoded */
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $events = $decoded['events'] ?? [];
        if ([] === $events) {
            return null;
        }

        $first = $events[0];
        $last = $events[\count($events) - 1];

        $status = 'running';
        $lastType = (string) ($last['eventType'] ?? '');
        if (\str_contains($lastType, 'WORKFLOW_EXECUTION_COMPLETED')) {
            $status = 'completed';
        } elseif (\str_contains($lastType, 'WORKFLOW_EXECUTION_FAILED')
            || \str_contains($lastType, 'WORKFLOW_EXECUTION_TERMINATED')
            || \str_contains($lastType, 'WORKFLOW_EXECUTION_TIMED_OUT')
            || \str_contains($lastType, 'WORKFLOW_EXECUTION_CANCELED')) {
            $status = 'failed';
        }

        $startedAtIso = (string) ($first['eventTime'] ?? '');
        $endedAtIso = (string) ($last['eventTime'] ?? $startedAtIso);
        $startedAt = $this->formatTimestamp($startedAtIso);
        $duration = $this->formatDuration($startedAtIso, $endedAtIso);

        /** @var array<string, mixed> $startedAttributes */
        $startedAttributes = (array) ($first['workflowExecutionStartedEventAttributes'] ?? []);
        /** @var array<string, mixed> $workflowType */
        $workflowType = (array) ($startedAttributes['workflowType'] ?? []);
        /** @var array<string, mixed> $taskQueue */
        $taskQueue = (array) ($startedAttributes['taskQueue'] ?? []);

        $eventsPreview = [];
        $tail = \array_slice($events, -6);
        foreach ($tail as $event) {
            $eventIsoTime = (string) ($event['eventTime'] ?? '');
            $eventType = (string) ($event['eventType'] ?? 'EVENT_TYPE_UNKNOWN');
            $eventsPreview[] = [
                'time' => '' !== $eventIsoTime ? (string) (new \DateTimeImmutable($eventIsoTime))->format('H:i:s') : '--:--:--',
                'type' => $this->normalizeEventType($eventType),
            ];
        }

        $baseName = \basename($file);
        $runId = \str_ends_with($baseName, '_events.json')
            ? \substr($baseName, 0, -11)
            : $baseName;

        return [
            'runId' => $runId,
            'workflowName' => (string) ($workflowType['name'] ?? 'UnknownWorkflow'),
            'status' => $status,
            'taskQueue' => (string) ($taskQueue['name'] ?? 'default'),
            'startedAt' => $startedAt,
            'duration' => $duration,
            'events' => $eventsPreview,
        ];
    }

    private function formatTimestamp(string $iso): string
    {
        if ('' === $iso) {
            return 'n/a';
        }

        try {
            return (new \DateTimeImmutable($iso))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return 'n/a';
        }
    }

    private function formatDuration(string $startedIso, string $endedIso): string
    {
        try {
            $startedAt = new \DateTimeImmutable($startedIso);
            $endedAt = new \DateTimeImmutable($endedIso);
            $seconds = \max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp());
            $hours = (int) \floor($seconds / 3600);
            $minutes = (int) \floor(($seconds % 3600) / 60);
            $remaining = $seconds % 60;

            return \sprintf('%02d:%02d:%02d', $hours, $minutes, $remaining);
        } catch (\Exception) {
            return '00:00:00';
        }
    }

    private function normalizeEventType(string $eventType): string
    {
        $normalized = \str_replace('EVENT_TYPE_', '', $eventType);
        return \str_replace('_', ' ', $normalized);
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
    private function fallbackRuns(): array
    {
        return [
            [
                'runId' => 'demo-run-001',
                'workflowName' => 'OrderFulfillment',
                'status' => 'running',
                'taskQueue' => 'default',
                'startedAt' => '2026-04-06 13:25:32',
                'duration' => '00:04:12',
                'events' => [
                    ['time' => '13:25:32', 'type' => 'WORKFLOW EXECUTION STARTED'],
                    ['time' => '13:25:35', 'type' => 'ACTIVITY TASK SCHEDULED'],
                    ['time' => '13:29:44', 'type' => 'WORKFLOW TASK STARTED'],
                ],
            ],
            [
                'runId' => 'demo-run-002',
                'workflowName' => 'InvoicePipeline',
                'status' => 'completed',
                'taskQueue' => 'default',
                'startedAt' => '2026-04-06 12:58:03',
                'duration' => '00:01:47',
                'events' => [
                    ['time' => '12:58:03', 'type' => 'WORKFLOW EXECUTION STARTED'],
                    ['time' => '12:58:10', 'type' => 'ACTIVITY TASK COMPLETED'],
                    ['time' => '12:59:50', 'type' => 'WORKFLOW EXECUTION COMPLETED'],
                ],
            ],
            [
                'runId' => 'demo-run-003',
                'workflowName' => 'BookingSaga',
                'status' => 'failed',
                'taskQueue' => 'payments',
                'startedAt' => '2026-04-06 12:40:11',
                'duration' => '00:00:53',
                'events' => [
                    ['time' => '12:40:11', 'type' => 'WORKFLOW EXECUTION STARTED'],
                    ['time' => '12:40:32', 'type' => 'ACTIVITY TASK FAILED'],
                    ['time' => '12:41:04', 'type' => 'WORKFLOW EXECUTION FAILED'],
                ],
            ],
        ];
    }
}
