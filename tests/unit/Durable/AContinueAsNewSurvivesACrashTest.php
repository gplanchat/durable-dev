<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CrashingContinueAsNewChain.php';

/**
 * The process stops at one step of a continue-as-new, and the transport redelivers the resume of
 * the old run. Whatever the step, the chain goes on and the parent hears once from its end (#881).
 */
final class AContinueAsNewSurvivesACrashTest extends TestCase
{
    /**
     * Each step, with sends held until the handler returns (Messenger) or sent at once (Laravel queue).
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function crashPoints(): iterable
    {
        $points = [
            'after the next run is linked' => 'link',
            'after the next run is saved' => 'save',
            'at the start of the next run' => 'append',
            'at the dispatch of the next run' => 'dispatch',
            'before the old run is marked completed' => 'before markCompleted',
            'after the old run is marked completed' => 'after markCompleted',
            'after the old run is unlinked' => 'unlink',
        ];
        foreach ($points as $name => $point) {
            yield "{$name}, sends held" => [$point, true];
            yield "{$name}, sends at once" => [$point, false];
        }
    }

    #[DataProvider('crashPoints')]
    public function testTheChainEndsOnceWhereverTheCrash(string $point, bool $deferred): void
    {
        $chain = new CrashingContinueAsNewChain($point, $deferred);

        try {
            $chain->resume('child-1');
            self::fail("No crash at {$point}.");
        } catch (\LogicException $e) {
            self::assertSame($point, $e->getMessage());
        }
        $chain->resume('child-1');
        $chain->driveStartedRuns();

        $runs = array_values(array_unique($chain->startedRuns));
        self::assertCount(2, $runs, 'two runs follow child-1, each started once');
        foreach ($runs as $run) {
            self::assertCount(1, $chain->eventsOf($run, ExecutionStarted::class), "{$run} has one start");
        }
        self::assertCount(1, $chain->eventsOf('child-1', WorkflowContinuedAsNew::class));
        self::assertCount(1, $chain->eventsOf($runs[0], WorkflowContinuedAsNew::class));

        $outcomes = [...$chain->eventsOf('parent-1', ChildWorkflowCompleted::class), ...$chain->eventsOf('parent-1', ChildWorkflowFailed::class)];
        self::assertCount(1, $outcomes);
        self::assertInstanceOf(ChildWorkflowCompleted::class, $outcomes[0]);
        self::assertSame('child-1', $outcomes[0]->childExecutionId()->toString());
        self::assertSame('done at 2', $outcomes[0]->result());

        self::assertSame([], $chain->links->getChildExecutionIdsForParent(ExecutionId::fromString('parent-1')), 'no link left');
    }

    /**
     * The first attempt sent the next run before it stopped, and the chain finished before the
     * redelivery came: the redelivery leaves the finished runs as they are.
     */
    public function testARedeliveryAfterTheChainEndedReopensNothing(): void
    {
        $chain = new CrashingContinueAsNewChain('before markCompleted', deferred: false);

        try {
            $chain->resume('child-1');
            self::fail('No crash before markCompleted().');
        } catch (\LogicException) {
        }
        $chain->driveStartedRuns();

        $chain->resume('child-1');
        $chain->driveStartedRuns();

        $runs = array_values(array_unique($chain->startedRuns));
        self::assertCount(2, $runs);
        foreach (['child-1', ...$runs] as $run) {
            self::assertTrue($chain->get(ExecutionId::fromString($run))['completed'] ?? false, "{$run} stays completed");
        }
        self::assertCount(1, $chain->eventsOf($runs[1], ExecutionCompleted::class), 'the last run completes once');
        self::assertCount(1, $chain->eventsOf('parent-1', ChildWorkflowCompleted::class));
    }

    /**
     * Before #881, the old run was marked completed and unlinked first: a crash at the save left
     * the next run linked, with no row and no start. A redelivery of the old run now resumes it.
     */
    public function testARedeliveryRepairsAChainBrokenBeforeTheFix(): void
    {
        $chain = new CrashingContinueAsNewChain('append');

        try {
            $chain->resume('child-1');
            self::fail('No crash at the start of the next run.');
        } catch (\LogicException) {
        }
        $next = $chain->eventsOf('child-1', WorkflowContinuedAsNew::class)[0]->newExecutionId();
        self::assertNotNull($next);
        $chain->delete($next);
        $chain->markCompleted(ExecutionId::fromString('child-1'));
        $chain->unlink(ExecutionId::fromString('child-1'));

        $chain->resume('child-1');
        $chain->driveStartedRuns();

        self::assertSame($next->toString(), $chain->startedRuns[0] ?? null, 'the run the journal names');
        self::assertCount(1, $chain->eventsOf($next->toString(), ExecutionStarted::class));
        $outcomes = $chain->eventsOf('parent-1', ChildWorkflowCompleted::class);
        self::assertCount(1, $outcomes);
        self::assertSame('done at 2', $outcomes[0]->result());
    }
}
