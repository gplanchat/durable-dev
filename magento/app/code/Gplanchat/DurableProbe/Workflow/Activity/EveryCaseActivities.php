<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * The contract of the exhaustive demonstration workflow: one activity per shape the observation
 * screen has to know how to show.
 *
 * The bench only had activities that succeed. A screen that has never seen a failure has never
 * proved it knew how to show one — and "the failure colour works" is not verified on an execution
 * that fails nowhere.
 */
interface EveryCaseActivities
{
    /** The nominal case: it succeeds first time. */
    #[AsActivityMethod(name: 'durable.case.succeed')]
    public function succeed(string $caseId): string;

    /**
     * It fails the first two times, then succeeds.
     *
     * It is the only way to get an `ACTIVITY_TASK_FAILED` **followed by a retry**: an action that
     * carries both red and a green ending, and that proves the colour marks the event and not the
     * whole action.
     */
    #[AsActivityMethod(name: 'durable.case.flaky')]
    public function flaky(string $caseId): string;

    /** It always fails, and its failure is final. */
    #[AsActivityMethod(name: 'durable.case.doomed')]
    public function doomed(string $caseId): string;
}
