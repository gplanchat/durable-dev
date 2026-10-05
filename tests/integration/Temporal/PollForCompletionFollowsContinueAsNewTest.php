<?php

declare(strict_types=1);

namespace integration\Temporal;

/**
 * pollForCompletion() follows a continue-as-new chain to its last run, as the in-memory path
 * does (#934).
 */
final class PollForCompletionFollowsContinueAsNewTest extends TemporalServerTestCase
{
    public function testItReturnsTheResultOfTheLastRun(): void
    {
        $executionId = $this->startWorkflow('CountsDown', ['n' => 2]);

        $result = $this->workflowClient()->pollForCompletion($executionId, 250, 160);

        self::assertSame(['done' => 'last run'], $result);
    }

    public function testItThrowsTheFailureOfTheLastRun(): void
    {
        $executionId = $this->startWorkflow('CountsDownThenFails', ['n' => 2]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('chain ended in failure');

        $this->workflowClient()->pollForCompletion($executionId, 250, 160);
    }
}
