<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DataCollector;

use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Expose la trace Durable dans la Web Debug Toolbar et le profiler Symfony.
 */
final class DurableDataCollector extends DataCollector implements ResetInterface
{
    public function __construct(
        private readonly DurableExecutionTrace $trace,
    ) {
    }

    #[\Override]
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $timeline = $this->trace->getTimeline();
        $this->data = [
            'timeline' => $timeline,
            'workflow_count' => $this->trace->countWorkflowEvents(),
            'activity_count' => $this->trace->countActivityEvents(),
            'executions' => $this->groupTimelineByExecution($timeline),
        ];
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

    public function getWorkflowCount(): int
    {
        return (int) ($this->data['workflow_count'] ?? 0);
    }

    public function getActivityCount(): int
    {
        return (int) ($this->data['activity_count'] ?? 0);
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
