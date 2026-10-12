<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\RunNarrator;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Observation\WorkflowRunEventPhase;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use PHPUnit\Framework\TestCase;

/**
 * A run's history, told as one line per event and ending with what the run waits on.
 *
 * The last line is read from the run description: the label of `await(…, label: 'signal approve')`
 * is recorded there at each suspension, so the story needs no new fact in the journal.
 */
final class RunNarratorTest extends TestCase
{
    public function testEachEventIsOneLineWithItsTimeKindNamePhaseAndAttempt(): void
    {
        $story = RunNarrator::story($this->running(null), [
            new WorkflowRunEvent(1, new \DateTimeImmutable('2026-10-12 14:02:11'), WorkflowRunEventKind::Execution, 'OrderWorkflow'),
            new WorkflowRunEvent(2, new \DateTimeImmutable('2026-10-12 14:02:12'), WorkflowRunEventKind::Activity, 'charge', phase: WorkflowRunEventPhase::Requested),
            new WorkflowRunEvent(3, new \DateTimeImmutable('2026-10-12 14:02:13'), WorkflowRunEventKind::Activity, 'charge', phase: WorkflowRunEventPhase::Started, attempt: 2),
        ]);

        self::assertSame('14:02:11  execution OrderWorkflow', $story[0]);
        self::assertSame('14:02:12  activity  charge requested', $story[1]);
        self::assertSame('14:02:13  activity  charge started (attempt 2)', $story[2]);
    }

    public function testARunningRunEndsWithWhatItWaitsOn(): void
    {
        $story = RunNarrator::story($this->running('signal payment.confirmed'), []);

        self::assertSame('Waiting on: signal payment.confirmed', end($story));
    }

    public function testAnUnlabelledConditionNamesWhereItIsWritten(): void
    {
        // What a closure without a label reports: enough to find the line, and honest about it.
        $story = RunNarrator::story($this->running('condition at /app/Workflow/OrderWorkflow.php:42'), []);

        self::assertSame('Waiting on: condition at /app/Workflow/OrderWorkflow.php:42', end($story));
    }

    public function testARunningRunWithNoWaitSaysNothingWasRecorded(): void
    {
        $story = RunNarrator::story($this->running(null), []);

        self::assertStringStartsWith('Waiting on: nothing recorded', end($story));
    }

    public function testAnEndedRunSaysHowItEnded(): void
    {
        $story = RunNarrator::story(new WorkflowRunDescription('exec-1', 'OrderWorkflow', WorkflowRunStatus::Completed), []);

        self::assertSame('Ended: completed', end($story));
    }

    private function running(?string $waitingOn): WorkflowRunDescription
    {
        return new WorkflowRunDescription('exec-1', 'OrderWorkflow', WorkflowRunStatus::Running, waitingOn: $waitingOn);
    }
}
