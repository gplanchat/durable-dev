<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Unit;

use Gplanchat\Durable\Bundle\Support\AsyncChildWorkflowFailureProjector;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class AsyncChildWorkflowFailureProjectorTest extends TestCase
{
    #[Test]
    public function usesLastWorkflowExecutionFailedFromChildJournalWhenPresent(): void
    {
        $store = new InMemoryEventStore();
        $childId = '01900000-0000-7000-8000-0000000000e1';
        $store->append(new ExecutionStarted($childId));
        $store->append(WorkflowExecutionFailed::workflowHandlerFailure($childId, new \RuntimeException('boom', 7)));

        $evt = AsyncChildWorkflowFailureProjector::toParentJournalEvent(
            $store,
            'parent-1',
            $childId,
            new \LogicException('handler-wrapper'),
        );

        self::assertSame('parent-1', $evt->executionId());
        self::assertSame($childId, $evt->childExecutionId());
        self::assertSame('boom', $evt->failureMessage());
        self::assertSame(7, $evt->failureCode());
        self::assertSame(WorkflowExecutionFailed::KIND_WORKFLOW_HANDLER, $evt->workflowFailureKind());
        self::assertSame(\RuntimeException::class, $evt->workflowFailureClass());
        self::assertSame([], $evt->workflowFailureContext());
    }

    #[Test]
    public function fallsBackToThrowableWhenNoWorkflowExecutionFailedInChildStream(): void
    {
        $store = new InMemoryEventStore();
        $childId = '01900000-0000-7000-8000-0000000000e2';
        $store->append(new ExecutionStarted($childId));

        $cause = new \InvalidArgumentException('no-wef', 11);
        $evt = AsyncChildWorkflowFailureProjector::toParentJournalEvent($store, 'p', $childId, $cause);

        self::assertSame('no-wef', $evt->failureMessage());
        self::assertSame(11, $evt->failureCode());
        self::assertNull($evt->workflowFailureKind());
        self::assertNull($evt->workflowFailureClass());
        self::assertSame([], $evt->workflowFailureContext());
    }
}
