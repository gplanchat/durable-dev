<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ChildWorkflowDeadline;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\DurableChildWorkflowFailedException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowTimeouts;
use PHPUnit\Framework\TestCase;

/**
 * Temporal fails the parent's await with "Child workflow <id> timed out." when the child outlives
 * its execution timeout (#979). The in-memory runner checks the deadline when the child's clock jumps
 * to its next timer.
 */
final class AChildThatExceedsItsExecutionTimeoutFailsItsParentTest extends TestCase
{
    public function testTheParentIsSettledWithATimedOutFailure(): void
    {
        $env = WorkflowTestEnvironment::inMemory();
        $env->registerWorkflowClass(ChildThatOutlivesItsTimeout::class);

        try {
            $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->childWorkflowStub(
                ChildThatOutlivesItsTimeout::class,
                new ChildWorkflowOptions(workflowId: 'slow-child', timeouts: new WorkflowTimeouts(execution: Duration::seconds(5))),
            )->run()), 'parent-timeout');
            self::fail('the child sleeps 60 s under a 5 s execution timeout: the parent must fail');
        } catch (DurableChildWorkflowFailedException $e) {
            self::assertSame('Child workflow slow-child timed out.', $e->getMessage());
        }
    }

    public function testTheChildIsNoLongerRunningAndTheParentJournalNamesTheDeadline(): void
    {
        $env = WorkflowTestEnvironment::inMemory();
        $env->registerWorkflowClass(ChildThatOutlivesItsTimeout::class);

        try {
            $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->childWorkflowStub(
                ChildThatOutlivesItsTimeout::class,
                new ChildWorkflowOptions(workflowId: 'slow-child', timeouts: new WorkflowTimeouts(execution: Duration::seconds(5))),
            )->run()), 'parent-journal');
            self::fail('the parent must fail');
        } catch (DurableChildWorkflowFailedException $e) {
            self::assertNull($e->workflowFailureKind(), 'Temporal leaves the kind empty, and so does the journal');
            self::assertNull($e->workflowFailureClass());
        }

        $store = $env->getEventStore();
        self::assertFalse(ParentChildWorkflowCoordinator::isChildRunActive($store, 'slow-child'));
        $parent = array_map(static fn(Event $e): string => $e::class, iterator_to_array($store->readStream(ExecutionId::fromString('parent-journal')), false));
        self::assertContains(ChildWorkflowDeadline::class, $parent);
        self::assertContains(ChildWorkflowFailed::class, $parent);
        $failure = array_values(array_filter(iterator_to_array($store->readStream(ExecutionId::fromString('parent-journal')), false), static fn(Event $e): bool => $e instanceof ChildWorkflowFailed))[0];
        self::assertNull($failure->workflowFailureKind());
    }

    public function testTheRunThatWasStoppedGetsTheTerminalEvent(): void
    {
        $env = WorkflowTestEnvironment::inMemory();
        $env->registerWorkflowClass(ChildThatContinuesThenOutlivesItsTimeout::class);

        try {
            $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->childWorkflowStub(
                ChildThatContinuesThenOutlivesItsTimeout::class,
                new ChildWorkflowOptions(workflowId: 'chain-child', timeouts: new WorkflowTimeouts(execution: Duration::seconds(5))),
            )->run()), 'parent-chain');
            self::fail('the second run sleeps 60 s under a 5 s execution timeout: the parent must fail');
        } catch (DurableChildWorkflowFailedException) {
        }

        $store = $env->getEventStore();
        $first = iterator_to_array($store->readStream(ExecutionId::fromString('chain-child')), false);
        $continued = array_values(array_filter($first, static fn(Event $e): bool => $e instanceof WorkflowContinuedAsNew))[0];
        $messages = static fn(array $events): array => array_map(
            static fn(WorkflowExecutionFailed $e): string => $e->failureMessage(),
            array_values(array_filter($events, static fn(Event $e): bool => $e instanceof WorkflowExecutionFailed)),
        );
        self::assertNotContains('Child workflow chain-child timed out.', $messages($first), 'the timeout is not written on the run that continued as new');
        self::assertContains('Child workflow chain-child timed out.', $messages(iterator_to_array($store->readStream($continued->newExecutionId()), false)), 'the run that was stopped ends');
    }

    public function testAChildThatEndsInTimeCompletesItsParent(): void
    {
        $env = WorkflowTestEnvironment::inMemory();
        $env->registerWorkflowClass(ChildThatOutlivesItsTimeout::class);

        $result = $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->childWorkflowStub(
            ChildThatOutlivesItsTimeout::class,
            new ChildWorkflowOptions(workflowId: 'slow-child', timeouts: new WorkflowTimeouts(execution: Duration::seconds(61))),
        )->run()), 'parent-in-time');

        self::assertSame('slept', $result);
    }
}

#[AsWorkflow(name: 'ChildThatOutlivesItsTimeout')]
final class ChildThatOutlivesItsTimeout
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(): string
    {
        $this->environment->await($this->environment->timer(60));

        return 'slept';
    }
}

#[AsWorkflow(name: 'ChildThatContinuesThenOutlivesItsTimeout')]
final class ChildThatContinuesThenOutlivesItsTimeout
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(int $n = 0): string
    {
        if ($n < 1) {
            $this->environment->continueAsNew(self::class, ['n' => $n + 1]);
        }
        $this->environment->await($this->environment->timer(60));

        return 'slept';
    }
}
