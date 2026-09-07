<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\RunTimeline;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use PHPUnit\Framework\TestCase;

/**
 * The frieze is computed **once**, next to the observation model, and it is measured in seconds.
 *
 * Two surfaces each derived it on their own side: Magento placed the actions in time, Sylius
 * stacked them. The same run therefore read differently depending on the application opened, when
 * it is the same journal underneath.
 *
 * ⚠ And it returns **seconds**, never percentages. The Magento block returned floats from 0 to
 * 100, that is to say a CSS width: the core would have started drawing for an API Platform surface
 * that renders no markup. Scaling is the host's job; measuring is ours.
 */
final class TheRunTimelinePositionsActionsInTimeTest extends TestCase
{
    public function testTheThreeEventsOfAnActivityAreOneLine(): void
    {
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:02.000', 'charge', actionKey: 'activity:act-1', started: true),
            $this->event(3, '12:00:05.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        self::assertCount(1, $timeline->actions);
        self::assertSame('charge', $timeline->actions[0]->label);
        self::assertCount(3, $timeline->actions[0]->events);
    }

    public function testAnActionIsPlacedAndMeasuredInSeconds(): void
    {
        // The fact not to lose: 22 seconds are worth 22.0, not "73.3 % of the bar". The host
        // scales with what it knows of its column; the core knows nothing of it.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'start'),
            $this->event(2, '12:00:08.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(3, '12:00:30.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        self::assertSame(30.0, $timeline->span);
        $charge = $timeline->actions[1];
        self::assertSame(8.0, $charge->offset);
        self::assertSame(22.0, $charge->duration);
    }

    public function testADurationIsWordedTheSameWayOnEverySurface(): void
    {
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:00.240', 'charge', actionKey: 'activity:act-1'),
        ]);

        self::assertSame('240 ms', $timeline->actions[0]->durationLabel);
        self::assertSame('240 ms', $timeline->spanLabel);
    }

    public function testWaitingToBePickedUpIsNotShownAsWork(): void
    {
        // The segment inherits the `started` of the event that **closes** it: what precedes being
        // picked up is the time spent waiting for somebody to be willing to start.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:20.000', 'charge', actionKey: 'activity:act-1', started: true),
            $this->event(3, '12:00:21.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        $segments = $timeline->actions[0]->segments;
        self::assertCount(2, $segments);
        self::assertTrue($segments[0]->waiting, 'the twenty seconds before being picked up are a queue');
        self::assertSame(20.0, $segments[0]->duration);
        self::assertFalse($segments[1]->waiting, 'the second that follows is work');
    }

    public function testAFailureIsCarriedByTheIntervalThatEndsOnIt(): void
    {
        // Painting the whole action would make an activity that succeeded on the second attempt
        // look like a lost one.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:01.000', 'charge', actionKey: 'activity:act-1', failed: true),
            $this->event(3, '12:00:02.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        $segments = $timeline->actions[0]->segments;
        self::assertTrue($segments[0]->failed);
        self::assertFalse($segments[1]->failed);
    }

    public function testASegmentNamesTheTwoEventsItSpans(): void
    {
        // The host composes its tooltip from the two ends, without recounting the indices: a
        // coupling by rank is exactly what one re-reads at three in the morning.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:01.000', 'charge', actionKey: 'activity:act-1', started: true),
        ]);

        $segment = $timeline->actions[0]->segments[0];
        self::assertSame(1, $segment->from->sequence);
        self::assertSame(2, $segment->to->sequence);
    }

    public function testAnEventThatIsItsOwnActionHasNoInterval(): void
    {
        // A lone mark already says everything there is to say about a moment: inventing a segment
        // for it would give it a duration it does not have.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'orderApproved', kind: WorkflowRunEventKind::Signal),
        ]);

        self::assertCount(1, $timeline->actions);
        self::assertSame([], $timeline->actions[0]->segments);
        self::assertSame(0.0, $timeline->actions[0]->duration);
    }

    public function testTwoEventsInTheSameMicrosecondAreNotSpreadByRank(): void
    {
        // Spreading by rank would pass an ordering off as a duration, and a one-millisecond run
        // would look like a one-hour run.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'start'),
            $this->event(2, '12:00:00.000', 'orderApproved', kind: WorkflowRunEventKind::Signal),
        ]);

        self::assertSame(0.0, $timeline->span);
        self::assertSame(0.0, $timeline->actions[0]->offset);
        self::assertSame(0.0, $timeline->actions[1]->offset);
    }

    public function testARunStillGoingEndsOnItsLastRecordedFact(): void
    {
        // The scale goes from the first to the last recorded event, not from the start to the end
        // of the execution: a running execution has no end, and the frieze claims to know nothing
        // more than what is written.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'start'),
            $this->event(2, '12:00:04.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        self::assertSame(4.0, $timeline->span);
    }

    public function testAnEmptyHistoryIsAnEmptyTimelineRatherThanNothing(): void
    {
        // A purged execution, or one never seen, is not a call error: the host must be able to
        // display it without having anything to catch up on.
        $timeline = RunTimeline::of([]);

        self::assertSame([], $timeline->actions);
        self::assertSame([], $timeline->journal(), 'array_merge() with no argument, and not an error');
        self::assertSame(0.0, $timeline->span);
    }

    public function testEachEventKeepsItsOwnPositionInsideItsAction(): void
    {
        // An event's mark is placed in the run's time, not in that of its action: that is what
        // makes it possible to find it on the same vertical as those of the other rows.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'start'),
            $this->event(2, '12:00:03.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(3, '12:00:07.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        $marks = $timeline->actions[1]->events;
        self::assertSame(3.0, $marks[0]->offset);
        self::assertSame(7.0, $marks[1]->offset);
        self::assertSame('charge', $marks[1]->event->label);
    }

    public function testASegmentSaysWhatItIsInWordsBothHostsShare(): void
    {
        // A hatching with no legend is a guessing game, and whoever hovers the bar is precisely
        // the one who wants to know. That both surfaces say it with the same words is not
        // coquetry: an operator moving from one to the other must have nothing to translate.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:20.000', 'charge', actionKey: 'activity:act-1', started: true),
        ]);

        $title = $timeline->actions[0]->segments[0]->title;
        self::assertStringContainsString('waiting to be picked up', $title);
        self::assertStringContainsString('20.0 s', $title);
        self::assertStringContainsString('#1', $title);
        self::assertStringContainsString('#2', $title);
    }

    public function testAWorkingIntervalDoesNotClaimToBeAWait(): void
    {
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:01.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        self::assertStringNotContainsString('waiting', $timeline->actions[0]->segments[0]->title);
    }

    public function testAMarkNamesItsRankItsMomentAndItsEvent(): void
    {
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'start'),
            $this->event(2, '12:00:03.250', 'charge', actionKey: 'activity:act-1'),
        ]);

        $title = $timeline->actions[1]->events[0]->title;
        self::assertStringContainsString('#2', $title);
        self::assertStringContainsString('12:00:03.250', $title);
        self::assertStringContainsString('charge', $title);
    }

    public function testWhatTheBackendRecordedIsRenderedOnceForEverySurface(): void
    {
        // Sylius passed the payload to `json_encode` without tolerance and rendered an empty
        // unfoldable as soon as one byte was not UTF-8. The formatting is decided here, once.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1', details: ['orderId' => 'ORD-7']),
            $this->event(2, '12:00:01.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        $marks = $timeline->actions[0]->events;
        self::assertIsString($marks[0]->renderedDetails);
        self::assertStringContainsString('ORD-7', $marks[0]->renderedDetails);
        self::assertNull($marks[1]->renderedDetails, 'nothing recorded, nothing to unfold');
    }

    public function testEveryRowOfTheJournalNamesItsActionAndNotItsEvent(): void
    {
        // Only the scheduling knows the activity's name: its follow-ups carry nothing but a
        // number, and a table surface therefore displayed `ACTIVITY TASK STARTED` on two rows out
        // of three, where the operator was looking for `charge`.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:01.000', 'ActivityTaskStarted', actionKey: 'activity:act-1', started: true),
        ]);

        $rows = $timeline->journal();
        self::assertSame(['charge', 'charge'], array_map(static fn($row): string => $row->actionLabel, $rows));
        self::assertSame(['charge', 'ActivityTaskStarted'], array_map(static fn($row): string => $row->event->label, $rows));
    }

    public function testAnEventThatIsItsOwnActionNamesItself(): void
    {
        // Leaving the cell empty would suggest a hole; repeating its label in both columns
        // teaches nothing, but it is the truth and not a hole.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'orderApproved', kind: WorkflowRunEventKind::Signal),
        ]);

        self::assertSame('orderApproved', $timeline->journal()[0]->actionLabel);
    }

    public function testTheJournalComesBackInRecordedOrderAndNotGroupedByAction(): void
    {
        // The frieze groups to answer "how long"; the journal unrolls to answer "in what order".
        // Returning the second in the order of the first would make the order lie, and the order
        // is what an operator comes to read first.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:01.000', 'orderApproved', kind: WorkflowRunEventKind::Signal),
            $this->event(3, '12:00:02.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        self::assertSame([1, 2, 3], array_map(static fn($row): int => $row->event->sequence, $timeline->journal()));
    }

    private function event(
        int $sequence,
        string $at,
        string $label,
        WorkflowRunEventKind $kind = WorkflowRunEventKind::Activity,
        ?string $actionKey = null,
        bool $started = false,
        bool $failed = false,
        array $details = [],
    ): WorkflowRunEvent {
        return new WorkflowRunEvent(
            $sequence,
            new \DateTimeImmutable('2026-08-29 ' . $at, new \DateTimeZone('UTC')),
            $kind,
            $label,
            $details,
            $actionKey,
            $started,
            $failed,
        );
    }
}
