<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Bundle;

use Gplanchat\Durable\Bundle\Profiler\DurableProfilerEventPresentation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DurableProfilerEventPresentationTest extends TestCase
{
    #[Test]
    public function dispatchTimelineLabelDescribesResumeWithoutWorkflowType(): void
    {
        $label = DurableProfilerEventPresentation::dispatchTimelineLabel([
            'kind' => 'dispatch',
            'executionId' => 'exec-1',
            'workflowType' => '',
            'payload' => [],
            'isResume' => true,
            'transportNames' => 'workflow_jobs',
        ]);

        self::assertStringContainsString('Reprise Messenger', $label);
        self::assertStringContainsString('sans type dans le message', $label);
        self::assertStringContainsString('workflow_jobs', $label);
    }

    #[Test]
    public function dispatchTimelineLabelDescribesNewRunWithType(): void
    {
        $label = DurableProfilerEventPresentation::dispatchTimelineLabel([
            'kind' => 'dispatch',
            'executionId' => 'exec-2',
            'workflowType' => 'OrderFlow',
            'payload' => ['x' => 1],
            'isResume' => false,
            'transportNames' => null,
        ]);

        self::assertStringContainsString('OrderFlow', $label);
        self::assertStringContainsString('Nouveau run', $label);
    }
}
