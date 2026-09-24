<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * A pass that suspends abandons its fiber, and PHP runs the workflow's `finally` blocks when it
 * destroys it (#323). They run on every such pass; what they would await or schedule then must
 * neither escape the pass nor reach the journal.
 */
final class AbandonedPassTest extends TestCase
{
    private InMemoryEventStore $store;
    private ExecutionEngine $engine;

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore();
        $runtime = new ExecutionRuntime($this->store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true);
        $this->engine = new ExecutionEngine($this->store, $runtime);
    }

    public function testAFinallyThatAwaitsDoesNotBreakTheSuspension(): void
    {
        $handler = static function (WorkflowEnvironment $env): mixed {
            try {
                return $env->await($env->activityStub(SuiteActivities::class)->never());
            } finally {
                $env->await($env->timer(60.0));
            }
        };

        $this->expectException(WorkflowSuspendedException::class);

        $this->engine->start('exec-1', $handler);
    }

    public function testWhatAFinallySchedulesWhileAbandonedStaysOutOfTheJournal(): void
    {
        $finallyRuns = 0;
        $handler = static function (WorkflowEnvironment $env) use (&$finallyRuns): mixed {
            try {
                return $env->await($env->activityStub(SuiteActivities::class)->never());
            } finally {
                ++$finallyRuns;
                $env->activityStub(SuiteActivities::class)->echoValue('cleanup');
            }
        };

        foreach (['start', 'resume'] as $pass) {
            try {
                $this->engine->{$pass}('exec-2', $handler);
                self::fail('the workflow waits on its activity');
            } catch (WorkflowSuspendedException) {
            }
        }

        self::assertSame(2, $finallyRuns, 'finally runs on each abandoned pass, as on a Temporal eviction');
        $scheduled = array_filter(
            iterator_to_array($this->store->readStream('exec-2'), false),
            static fn(object $e): bool => $e instanceof ActivityScheduled,
        );
        self::assertCount(1, $scheduled, 'only the awaited activity is in the journal');
    }
}
