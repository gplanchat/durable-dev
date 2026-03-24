<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Unit;

use Gplanchat\Durable\ChildWorkflowRunner;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\Query\WorkflowQueryEvaluator;
use Gplanchat\Durable\Store\EventSerializer;
use Gplanchat\Durable\Tests\Support\DurableTestCase;
use Gplanchat\Durable\Tests\Support\StepwiseWorkflowHarness;
use Gplanchat\Durable\Tests\Support\Workflow\BadChildWorkflow;
use Gplanchat\Durable\Tests\Support\Workflow\ChildWorkflow;
use Gplanchat\Durable\Tests\Support\Workflow\EchoChildWorkflow;
use Gplanchat\Durable\Tests\Support\Workflow\ParentCollidingChildWorkflow;
use Gplanchat\Durable\Tests\Support\Workflow\ParentOfBadChildWorkflow;
use Gplanchat\Durable\Tests\Support\Workflow\ParentReportsChildFailureKindWorkflow;
use Gplanchat\Durable\Tests\Support\Workflow\ParentWorkflow;
use Gplanchat\Durable\Tests\Support\Workflow\ParWithOptsWorkflow;
use Gplanchat\Durable\Tests\Support\Workflow\ReturnOneWorkflow;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
#[CoversClass(ChildWorkflowRunner::class)]
#[CoversClass(WorkflowQueryEvaluator::class)]
final class TemporalParityFeaturesTest extends DurableTestCase
{
    /**
     * @return array{0: array<string, mixed>, 1: WorkflowRegistry, 2: ExecutionEngine}
     */
    private function engineWithChildRunner(): array
    {
        $stack = $this->stack();
        $registry = new WorkflowRegistry();
        $childRunner = new ChildWorkflowRunner(
            $stack['eventStore'],
            $stack['runtime'],
            $registry,
            $stack['activityExecutor'],
            0,
        );
        $engine = new ExecutionEngine($stack['eventStore'], $stack['runtime'], $childRunner);

        return [$stack, $registry, $engine];
    }

    #[Test]
    public function executeChildWorkflowRunsRegisteredChildAndRecordsParentJournal(): void
    {
        [$stack, $registry, $engine] = $this->engineWithChildRunner();
        $registry->registerClass(ChildWorkflow::class);
        $registry->registerClass(ParentWorkflow::class);

        $executionId = $this->executionId();
        $result = $engine->start($executionId, $registry->getHandler('Parent', []));

        self::assertSame(70, $result);
        $classes = array_map(
            static fn ($e) => $e::class,
            iterator_to_array($stack['eventStore']->readStream($executionId)),
        );
        self::assertContains(\Gplanchat\Durable\Event\ChildWorkflowScheduled::class, $classes);
        self::assertContains(\Gplanchat\Durable\Event\ChildWorkflowCompleted::class, $classes);
    }

    #[Test]
    public function executeChildWorkflowHonoursChildWorkflowOptionsOnJournal(): void
    {
        [$stack, $registry, $engine] = $this->engineWithChildRunner();
        $registry->registerClass(EchoChildWorkflow::class);
        $registry->registerClass(ParWithOptsWorkflow::class);

        $executionId = $this->executionId();
        $result = $engine->start($executionId, $registry->getHandler('ParWithOpts', []));

        self::assertSame(26, $result);
        foreach ($stack['eventStore']->readStream($executionId) as $e) {
            if ($e instanceof \Gplanchat\Durable\Event\ChildWorkflowScheduled) {
                self::assertSame('stable-child-id', $e->childExecutionId());
                self::assertSame('stable-child-id', $e->requestedWorkflowId());
                self::assertSame(ParentClosePolicy::RequestCancel, $e->parentClosePolicy());

                return;
            }
        }
        self::fail('ChildWorkflowScheduled attendu');
    }

    #[Test]
    public function duplicateExplicitChildWorkflowIdThrowsInvalidArgumentException(): void
    {
        [$stack, $registry, $engine] = $this->engineWithChildRunner();
        $stack['eventStore']->append(new ExecutionStarted('colliding-child'));
        $registry->registerClass(ReturnOneWorkflow::class);
        $registry->registerClass(ParentCollidingChildWorkflow::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('colliding-child');
        $engine->start($this->executionId(), $registry->getHandler('P', []));
    }

    #[Test]
    public function childWorkflowFailureSurfacesAsDurableChildWorkflowFailedException(): void
    {
        [$stack, $registry, $engine] = $this->engineWithChildRunner();
        $registry->registerClass(BadChildWorkflow::class);
        $registry->registerClass(ParentOfBadChildWorkflow::class);

        $executionId = $this->executionId();
        $out = $engine->start($executionId, $registry->getHandler('Par', []));

        self::assertSame('caught', $out);
        $hasFailed = false;
        foreach ($stack['eventStore']->readStream($executionId) as $e) {
            if ($e instanceof ChildWorkflowFailed) {
                $hasFailed = true;
                self::assertStringContainsString('child boom', $e->failureMessage());
            }
        }
        self::assertTrue($hasFailed);
    }

    #[Test]
    public function replayChildWorkflowFailedMapsRichJournalFieldsOntoDurableChildWorkflowFailedException(): void
    {
        [$stack, $registry, $engine] = $this->engineWithChildRunner();
        $registry->registerClass(ParentReportsChildFailureKindWorkflow::class);

        $executionId = $this->executionId();
        $childId = 'child-rich-replay-1';
        $stack['eventStore']->append(new ExecutionStarted($executionId));
        $stack['eventStore']->append(new \Gplanchat\Durable\Event\ChildWorkflowScheduled(
            $executionId,
            $childId,
            'Ghost',
            [],
            ParentClosePolicy::Terminate,
            null,
        ));
        $stack['eventStore']->append(new ChildWorkflowFailed(
            $executionId,
            $childId,
            'boom',
            9,
            WorkflowExecutionFailed::KIND_WORKFLOW_HANDLER,
            \RuntimeException::class,
            ['traceId' => 'abc'],
        ));

        $out = $engine->resume($executionId, $registry->getHandler('ParReport', []));
        $decoded = json_decode((string) $out, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(WorkflowExecutionFailed::KIND_WORKFLOW_HANDLER, $decoded['kind']);
        self::assertSame(\RuntimeException::class, $decoded['class']);
        self::assertSame(['traceId' => 'abc'], $decoded['ctx']);
    }

    #[Test]
    public function continueAsNewAppendsEventAndThrowsContinueAsNewRequested(): void
    {
        $stack = $this->stack();
        $engine = new ExecutionEngine($stack['eventStore'], $stack['runtime'], null);
        $executionId = $this->executionId();

        try {
            $engine->start($executionId, static fn (WorkflowEnvironment $env) => $env->continueAsNew('FollowUp', ['n' => 1]));
            self::fail('ContinueAsNewRequested expected');
        } catch (ContinueAsNewRequested $e) {
            self::assertSame('FollowUp', $e->workflowType);
            self::assertSame(['n' => 1], $e->payload);
        }

        $lastContinued = null;
        foreach ($stack['eventStore']->readStream($executionId) as $event) {
            if ($event instanceof WorkflowContinuedAsNew) {
                $lastContinued = $event;
            }
        }
        self::assertInstanceOf(WorkflowContinuedAsNew::class, $lastContinued);
        self::assertSame('FollowUp', $lastContinued->nextWorkflowType());
        self::assertSame(['n' => 1], $lastContinued->nextPayload());

        $hasCompleted = false;
        foreach ($stack['eventStore']->readStream($executionId) as $event) {
            if ($event instanceof ExecutionCompleted) {
                $hasCompleted = true;
            }
        }
        self::assertFalse($hasCompleted, 'pas de ExecutionCompleted après continue-as-new');
    }

    #[Test]
    public function waitSignalResolvesFromJournalOnResume(): void
    {
        $stack = $this->stack();
        $harness = StepwiseWorkflowHarness::create(
            $stack['eventStore'],
            $stack['activityTransport'],
            $stack['activityExecutor'],
        );
        $executionId = $this->executionId();

        $workflow = static function (WorkflowEnvironment $env) {
            return $env->waitSignal('go');
        };

        self::assertTrue($harness->start($executionId, $workflow));
        $stack['eventStore']->append(new WorkflowSignalReceived($executionId, 'go', ['ok' => true]));
        self::assertFalse($harness->resume($executionId, $workflow));
        self::assertSame(['ok' => true], $harness->lastCompletedResult());
    }

    #[Test]
    public function waitUpdateResolvesFromJournalOnResume(): void
    {
        $stack = $this->stack();
        $harness = StepwiseWorkflowHarness::create(
            $stack['eventStore'],
            $stack['activityTransport'],
            $stack['activityExecutor'],
        );
        $executionId = $this->executionId();

        $workflow = static function (WorkflowEnvironment $env) {
            return $env->waitUpdate('addItem');
        };

        self::assertTrue($harness->start($executionId, $workflow));
        $stack['eventStore']->append(new WorkflowUpdateHandled($executionId, 'addItem', ['qty' => 2], 42));
        self::assertFalse($harness->resume($executionId, $workflow));
        self::assertSame(42, $harness->lastCompletedResult());
    }

    #[Test]
    public function workflowQueryEvaluatorReadsResultAndMessages(): void
    {
        $store = $this->stack()['eventStore'];
        $id = $this->executionId();
        $store->append(new ExecutionStarted($id));
        $store->append(new WorkflowSignalReceived($id, 'ping', ['seq' => 1]));
        $store->append(new WorkflowUpdateHandled($id, 'u', ['a' => 1], 'done'));
        $store->append(new ExecutionCompleted($id, 'final'));

        self::assertSame('final', WorkflowQueryEvaluator::lastExecutionResult($store, $id));
        self::assertSame([['name' => 'ping', 'payload' => ['seq' => 1]]], WorkflowQueryEvaluator::signalsReceived($store, $id));
        self::assertSame([['name' => 'u', 'arguments' => ['a' => 1], 'result' => 'done']], WorkflowQueryEvaluator::updatesHandled($store, $id));
    }

    #[Test]
    public function eventSerializerRoundTripsNewWorkflowEvents(): void
    {
        $cases = [
            new \Gplanchat\Durable\Event\ChildWorkflowScheduled('p1', 'c1', 'T', ['x' => 1]),
            new \Gplanchat\Durable\Event\ChildWorkflowScheduled('p1', 'c2', 'T2', [], ParentClosePolicy::Abandon, 'wanted-id'),
            new \Gplanchat\Durable\Event\ChildWorkflowCompleted('p1', 'c1', 99),
            new ChildWorkflowFailed('p1', 'c1', 'err', 5),
            new WorkflowContinuedAsNew('p1', 'W2', ['z' => 2]),
            new WorkflowSignalReceived('p1', 's', ['d' => 3]),
            new WorkflowUpdateHandled('p1', 'up', [1], null),
            new WorkflowCancellationRequested('c1', 'parent_request_cancel', 'p1'),
        ];
        foreach ($cases as $event) {
            $restored = EventSerializer::deserialize(EventSerializer::serialize($event));
            self::assertSame($event::class, $restored::class);
            self::assertSame($event->payload(), $restored->payload());
        }
    }

    #[Test]
    public function eventSerializerHydratesLegacyChildWorkflowScheduledWithoutNewFields(): void
    {
        $row = [
            'execution_id' => 'parent-x',
            'event_type' => \Gplanchat\Durable\Event\ChildWorkflowScheduled::class,
            'payload' => [
                'childExecutionId' => 'child-x',
                'childWorkflowType' => 'Legacy',
                'input' => ['k' => true],
            ],
        ];
        $event = EventSerializer::deserialize($row);
        self::assertInstanceOf(\Gplanchat\Durable\Event\ChildWorkflowScheduled::class, $event);
        self::assertSame(ParentClosePolicy::Terminate, $event->parentClosePolicy());
        self::assertNull($event->requestedWorkflowId());
    }
}
