<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\Profiler;

use Gplanchat\Durable\Bundle\Profiler\DurableProfilerEventPresentation;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The profiler panel is part of the bundle's public surface, and WA006 makes English the working
 * language of everything this repository ships. The labels were written in French; this test is
 * what keeps them from drifting back.
 *
 * The second assertion is the general one: a French accented letter anywhere in a label is the
 * cheapest signal that a string was written in the wrong language, and it costs one regex.
 */
final class TheProfilerSpeaksEnglishTest extends TestCase
{
    /**
     * @return iterable<string, array{Event, string}>
     */
    public static function storeEvents(): iterable
    {
        yield 'execution started' => [new ExecutionStarted('e-1', []), 'Execution started'];
        yield 'execution completed' => [new ExecutionCompleted('e-1', null), 'Execution finished'];
        yield 'continued as new' => [new WorkflowContinuedAsNew('e-1', 'Next', []), 'Continue as new'];
        yield 'activity scheduled' => [new ActivityScheduled('e-1', 'a-1', 'Charge', []), 'Activity queued'];
        yield 'activity completed' => [new ActivityCompleted('e-1', 'a-1', null), 'Activity succeeded'];
        yield 'activity cancelled' => [new ActivityCancelled('e-1', 'a-1', 'no reason'), 'Activity cancelled'];
        yield 'timer scheduled' => [new TimerScheduled('e-1', 't-1', 0.0), 'Timer scheduled'];
        yield 'timer fired' => [new TimerCompleted('e-1', 't-1'), 'Timer fired'];
        yield 'timer cancelled' => [new TimerCancelled('e-1', 't-1', 'no reason'), 'Timer cancelled'];
        yield 'side effect' => [new SideEffectRecorded('e-1', 's-1', null), 'Side effect recorded'];
        yield 'child scheduled' => [new ChildWorkflowScheduled('e-1', 'c-1', 'Child', []), 'Child workflow scheduled'];
        yield 'child completed' => [new ChildWorkflowCompleted('e-1', 'c-1', null), 'Child workflow finished'];
        yield 'signal' => [new WorkflowSignalReceived('e-1', 'approve', []), 'Signal received'];
        yield 'update' => [new WorkflowUpdateHandled('e-1', 'raise', [], null), 'Update handled'];
        yield 'execution cancelled' => [new WorkflowExecutionCancelled('e-1', 'no reason'), 'Execution cancelled'];
        yield 'cancellation requested' => [new WorkflowCancellationRequested('e-1', 'no reason'), 'Cancellation requested'];
    }

    #[DataProvider('storeEvents')]
    public function testAStoreEventIsTitledInEnglish(Event $event, string $expected): void
    {
        $presented = DurableProfilerEventPresentation::fromStoreEvent($event);

        self::assertSame($expected, $presented['title']);
        self::assertNotFrench($presented['title'] . ' ' . $presented['subtitle']);
    }

    public function testAProcessTraceIsTitledInEnglish(): void
    {
        self::assertSame(
            'Workflow started',
            DurableProfilerEventPresentation::fromProcessTrace(['kind' => 'workflow'])['title'],
        );
        self::assertSame(
            'Workflow resumed',
            DurableProfilerEventPresentation::fromProcessTrace(['kind' => 'workflow', 'isResume' => true])['title'],
        );
        self::assertSame(
            'Activity execution',
            DurableProfilerEventPresentation::fromProcessTrace(['kind' => 'activity'])['title'],
        );
        self::assertSame(
            'Event',
            DurableProfilerEventPresentation::fromProcessTrace([])['title'],
        );
    }

    public function testADispatchLabelIsWrittenInEnglish(): void
    {
        self::assertNotFrench(DurableProfilerEventPresentation::dispatchTimelineLabel([
            'isResume' => true,
            'transportNames' => 'async',
        ]));
        self::assertNotFrench(DurableProfilerEventPresentation::dispatchTimelineLabel([
            'workflowType' => 'OrderWorkflow',
        ]));
        self::assertNotFrench(DurableProfilerEventPresentation::dispatchTimelineLabel([]));
    }

    private static function assertNotFrench(string $label): void
    {
        self::assertSame(
            0,
            preg_match('/[àâçéèêëîïôùûœÀÂÇÉÈÊÎÔÛŒ]/u', $label),
            \sprintf('The label "%s" carries a French accented letter.', $label),
        );
    }
}
