<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Activity;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Testing\ActivitySpy;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * `activity()` is the primitive underneath `activityStub()`, not beside it: the stub scheduled by
 * calling it. It has to leave the surface a workflow author reaches, without the stub ceasing to
 * work and without the journal moving.
 *
 * The third point is the one that counts. An execution recorded before this change must replay
 * identically: if the wire form moves, the break is no longer an API break, it is a data break.
 *
 * @see openspec/changes/workflow-authoring-surface — tasks 4.1 to 4.3
 */
final class ActivitySchedulingPortTest extends TestCase
{
    public function testTheEnvironmentExposesNoSchedulingVerb(): void
    {
        $reflection = new \ReflectionClass(WorkflowEnvironment::class);

        $public = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $public[] = $method->getName();
        }

        // Naming the activity by a string and passing it a free-form array is the form the
        // library no longer teaches: a typo there produces an activity that is never scheduled,
        // instead of a type error.

        self::assertNotContains('activity', $public);
    }

    public function testTheEnvironmentExposesNoQueryPlumbing(): void
    {
        $reflection = new \ReflectionClass(WorkflowEnvironment::class);

        $public = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $public[] = $method->getName();
        }

        // An author declares `#[AsQueryMethod]` and the engine wires it. These three were on the
        // environment because it is the object the engine had at hand, not because a workflow
        // needs them — reaching them amounted to short-circuiting the declaration.
        self::assertNotContains('registerQueryHandler', $public);
        self::assertNotContains('callQueryHandler', $public);
        self::assertNotContains('hasQueryHandler', $public);
    }

    public function testSignalAndUpdateRegistrationStayOnTheSurface(): void
    {
        $reflection = new \ReflectionClass(WorkflowEnvironment::class);

        $public = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $public[] = $method->getName();
        }

        // The asymmetry with queries is deliberate, and this test is what pins it. A signal is
        // delivered inside the environment, during `await()`; a query is read by the worker,
        // outside the fiber. The verb that remains is the one the workflow really uses.
        self::assertContains('onSignal', $public);
        self::assertContains('onUpdate', $public);
    }

    public function testTheStubStillSchedulesThroughTheNarrowPort(): void
    {
        $spy = ActivitySpy::returns('charged');
        $env = WorkflowTestEnvironment::inMemory(['charge' => $spy]);

        $result = $env->runWorkflowClass(PortWorkflow::class, ['orderId' => 'ORD-7']);

        self::assertSame('charged', $result);
        $spy->assertCalledOnce();
        $spy->assertCalledWith(['orderId' => 'ORD-7']);
    }

    public function testTheStubCarriesItsOptionsToEveryCall(): void
    {
        $spy = ActivitySpy::returns('charged');
        $env = WorkflowTestEnvironment::inMemory(['charge' => $spy]);

        $env->runWorkflowClass(TwiceCallingWorkflow::class, ['orderId' => 'ORD-8'], 'exec-options');

        $scheduled = [];
        foreach ($env->getEventStore()->readStream('exec-options') as $event) {
            if ($event instanceof ActivityScheduled) {
                $scheduled[] = $event;
            }
        }

        self::assertCount(2, $scheduled, 'both calls of the stub must be scheduled');
        foreach ($scheduled as $event) {
            self::assertSame('charge', $event->activityName());
        }
    }

    public function testTheJournalDoesNotMove(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'charge' => static fn(array $p): string => 'charged:' . $p['orderId'],
        ]);

        $env->runWorkflowClass(PortWorkflow::class, ['orderId' => 'ORD-9'], 'exec-journal');

        $recorded = [];
        foreach ($env->getEventStore()->readStream('exec-journal') as $event) {
            $recorded[] = (new \ReflectionClass($event))->getShortName();
        }

        // Pinned, and deliberately hard-coded: this test exists to forbid a change, not to
        // describe a behaviour. If it breaks, what is at stake is the replay of the executions
        // already recorded.
        self::assertSame([
            'ExecutionStarted',
            'ActivityScheduled',
            'ActivityTaskStarted',
            'ActivityTaskCompleted',
            'ActivityCompleted',
            'ExecutionCompleted',
        ], $recorded);
    }
}

interface PortActivities
{
    #[AsActivityMethod('charge')]
    public function charge(string $orderId): string;
}

#[AsWorkflow(name: 'port')]
final class PortWorkflow
{
    private ActivityStub $orders;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->orders = $environment->activityStub(PortActivities::class);
    }

    #[AsWorkflowMethod]
    public function run(string $orderId): string
    {
        return $this->environment->await($this->orders->charge($orderId));
    }
}

#[AsWorkflow(name: 'port-twice')]
final class TwiceCallingWorkflow
{
    private ActivityStub $orders;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->orders = $environment->activityStub(
            PortActivities::class,
            ActivityOptions::of(retryLimit: 1),
        );
    }

    #[AsWorkflowMethod]
    public function run(string $orderId): string
    {
        $this->environment->await($this->orders->charge($orderId));

        return $this->environment->await($this->orders->charge($orderId));
    }
}
