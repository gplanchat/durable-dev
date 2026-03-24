<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Unit;

use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\ParentClosureReason;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Tests\Support\CallbackWorkflowResumeDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ParentChildWorkflowCoordinator::class)]
final class ParentChildWorkflowCoordinatorTest extends TestCase
{
    #[Test]
    public function terminateAppendsWorkflowExecutionFailedAndDispatchesResume(): void
    {
        $parentId = 'parent-1';
        $childId = 'child-1';
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($parentId));
        $store->append(new ChildWorkflowScheduled($parentId, $childId, 'T', [], ParentClosePolicy::Terminate));
        $store->append(new ExecutionStarted($childId));

        $resumes = [];
        $coordinator = new ParentChildWorkflowCoordinator(
            $store,
            new CallbackWorkflowResumeDispatcher(static function (string $id) use (&$resumes): void {
                $resumes[] = $id;
            }),
        );

        $coordinator->onParentClosed($parentId, ParentClosureReason::CompletedSuccessfully);

        self::assertSame([$childId], $resumes);
        $failed = null;
        foreach ($store->readStream($childId) as $e) {
            if ($e instanceof WorkflowExecutionFailed) {
                $failed = $e;
            }
        }
        self::assertInstanceOf(WorkflowExecutionFailed::class, $failed);
        self::assertSame(WorkflowExecutionFailed::KIND_TERMINATED_BY_PARENT, $failed->kind());
    }

    #[Test]
    public function abandonDoesNotTouchChildStream(): void
    {
        $parentId = 'p';
        $childId = 'c';
        $store = new InMemoryEventStore();
        $store->append(new ChildWorkflowScheduled($parentId, $childId, 'T', [], ParentClosePolicy::Abandon));
        $store->append(new ExecutionStarted($childId));

        $resumes = [];
        $coordinator = new ParentChildWorkflowCoordinator(
            $store,
            new CallbackWorkflowResumeDispatcher(static function (string $id) use (&$resumes): void {
                $resumes[] = $id;
            }),
        );

        $coordinator->onParentClosed($parentId, ParentClosureReason::Failed);

        self::assertSame([], $resumes);
        self::assertCount(1, iterator_to_array($store->readStream($childId)));
    }

    #[Test]
    public function requestCancelAppendsCancellationEventAndDispatchesResume(): void
    {
        $parentId = 'p2';
        $childId = 'c2';
        $store = new InMemoryEventStore();
        $store->append(new ChildWorkflowScheduled($parentId, $childId, 'T', [], ParentClosePolicy::RequestCancel));
        $store->append(new ExecutionStarted($childId));

        $resumes = [];
        $coordinator = new ParentChildWorkflowCoordinator(
            $store,
            new CallbackWorkflowResumeDispatcher(static function (string $id) use (&$resumes): void {
                $resumes[] = $id;
            }),
        );

        $coordinator->onParentClosed($parentId, ParentClosureReason::CompletedSuccessfully);

        self::assertSame([$childId], $resumes);
        $cancel = null;
        foreach ($store->readStream($childId) as $e) {
            if ($e instanceof WorkflowCancellationRequested) {
                $cancel = $e;
            }
        }
        self::assertInstanceOf(WorkflowCancellationRequested::class, $cancel);
        self::assertSame('parent_request_cancel', $cancel->reason());
        self::assertSame($parentId, $cancel->sourceParentExecutionId());
    }

    #[Test]
    public function skipsChildThatAlreadyCompleted(): void
    {
        $parentId = 'p3';
        $childId = 'c3';
        $store = new InMemoryEventStore();
        $store->append(new ChildWorkflowScheduled($parentId, $childId, 'T', [], ParentClosePolicy::Terminate));
        $store->append(new ExecutionStarted($childId));
        $store->append(new ExecutionCompleted($childId, 'ok'));

        $resumes = [];
        $coordinator = new ParentChildWorkflowCoordinator(
            $store,
            new CallbackWorkflowResumeDispatcher(static function (string $id) use (&$resumes): void {
                $resumes[] = $id;
            }),
        );

        $coordinator->onParentClosed($parentId, ParentClosureReason::Failed);

        self::assertSame([], $resumes);
    }
}
