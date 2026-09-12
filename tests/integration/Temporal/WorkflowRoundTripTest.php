<?php

declare(strict_types=1);

namespace integration\Temporal;

/**
 * A full round trip against a real server: the driver's commands must be **accepted**, not merely
 * well formed.
 */
final class WorkflowRoundTripTest extends TemporalServerTestCase
{
    public function testWorkflowWithoutActivityCompletes(): void
    {
        self::assertSame(['echo' => 21], $this->runWorkflow('Plain', ['value' => 21]));
    }

    public function testWorkflowAwaitingAnActivityCompletes(): void
    {
        self::assertSame(['doubled' => 42], $this->runWorkflow('Doubler', ['value' => 21]));
    }

    public function testTwoSequentialActivitiesReplayInOrder(): void
    {
        self::assertSame(['text' => '42!'], $this->runWorkflow('TwoActivities', ['value' => 21]));
    }

    public function testTimerFires(): void
    {
        self::assertSame(['slept' => true], $this->runWorkflow('Sleeper', []));
    }

    public function testSideEffectIsRecordedAndReplayed(): void
    {
        self::assertSame(['side' => 8], $this->runWorkflow('SideEffecting', ['seed' => 7]));
    }
}
