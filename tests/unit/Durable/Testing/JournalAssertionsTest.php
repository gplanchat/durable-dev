<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Testing;

use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\JournalAssertions;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * The one body behind `assertWorkflowFailed()`, which `DurableTestCase` and the bundle's test trait
 * used to carry twice (#373).
 */
final class JournalAssertionsTest extends TestCase
{
    public function testAFailedWorkflowPasses(): void
    {
        $store = $this->journalFailingWith(new \LogicException('boom'));

        JournalAssertions::assertWorkflowFailed($store, 'exec-1');
        JournalAssertions::assertWorkflowFailed($store, 'exec-1', \LogicException::class);
        $this->addToAssertionCount(1);
    }

    public function testAWorkflowThatDidNotFailIsReported(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted('exec-1', []));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The workflow "exec-1" did not fail');

        JournalAssertions::assertWorkflowFailed($store, 'exec-1');
    }

    public function testAnotherFailureClassIsReported(): void
    {
        $store = $this->journalFailingWith(new \LogicException('boom'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('failed with another class than the expected one');

        JournalAssertions::assertWorkflowFailed($store, 'exec-1', \RuntimeException::class);
    }

    public function testAnotherExecutionsFailureDoesNotCount(): void
    {
        $store = $this->journalFailingWith(new \LogicException('boom'));

        $this->expectException(AssertionFailedError::class);

        JournalAssertions::assertWorkflowFailed($store, 'exec-2');
    }

    private function journalFailingWith(\Throwable $cause): InMemoryEventStore
    {
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted('exec-1', []));
        $store->append(WorkflowExecutionFailed::workflowHandlerFailure('exec-1', $cause));

        return $store;
    }
}
