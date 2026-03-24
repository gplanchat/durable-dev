<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Unit;

use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventSerializer;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Tests\Support\DurableTestCase;
use Gplanchat\Durable\Tests\Support\StepwiseWorkflowHarness;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
#[CoversClass(\Gplanchat\Durable\ExecutionContext::class)]
#[CoversClass(SideEffectRecorded::class)]
final class SideEffectReplayTest extends DurableTestCase
{
    #[Test]
    public function sideEffectClosureRunsOnceAcrossSuspendResumeReplay(): void
    {
        $eventStore = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $executor->register('noop', static fn () => null);

        $harness = StepwiseWorkflowHarness::create($eventStore, $transport, $executor);
        $executionId = $this->executionId();

        $invocations = 0;
        $workflow = function (WorkflowEnvironment $env) use (&$invocations) {
            $n = $env->sideEffect(function () use (&$invocations) {
                ++$invocations;

                return random_int(10_000, 99_999);
            });
            $env->await($env->activity('noop', []));

            return $n;
        };

        self::assertTrue($harness->start($executionId, $workflow), 'suspend sur await activité après side effect');
        self::assertSame(1, $invocations, 'une seule exécution de la closure avant la reprise');

        self::assertTrue($harness->drainOneQueuedActivity($executionId));

        self::assertFalse($harness->resume($executionId, $workflow), 'terminé après replay + activité rejouée');
        $result = $harness->lastCompletedResult();
        self::assertIsInt($result);
        self::assertSame(1, $invocations, 'au replay, la closure du side effect ne doit pas être ré-exécutée');

        $types = [];
        foreach ($eventStore->readStream($executionId) as $event) {
            $types[] = $event::class;
        }
        self::assertContains(SideEffectRecorded::class, $types);
        self::assertContains(ActivityScheduled::class, $types);
    }

    #[Test]
    public function eventSerializerRoundTripsSideEffectRecorded(): void
    {
        $event = new SideEffectRecorded('exec-1', 'se-1', ['x' => 1, 'y' => 'z']);
        $row = EventSerializer::serialize($event);
        $restored = EventSerializer::deserialize($row);
        self::assertInstanceOf(SideEffectRecorded::class, $restored);
        self::assertSame('exec-1', $restored->executionId());
        self::assertSame('se-1', $restored->sideEffectId());
        self::assertSame(['x' => 1, 'y' => 'z'], $restored->result());
    }

    #[Test]
    public function inlineWorkflowAppendsSideEffectRecordedBeforeCompletion(): void
    {
        $stack = $this->stack();
        $engine = new ExecutionEngine($stack['eventStore'], $stack['runtime']);
        $executionId = $this->executionId();

        $out = $engine->start($executionId, function (WorkflowEnvironment $env) {
            return $env->sideEffect(static fn () => 42);
        });

        self::assertSame(42, $out);
        $classes = [];
        foreach ($stack['eventStore']->readStream($executionId) as $e) {
            $classes[] = $e::class;
        }
        self::assertSame(
            [ExecutionStarted::class, SideEffectRecorded::class, ExecutionCompleted::class],
            $classes,
        );
    }
}
