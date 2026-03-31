<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\ChildWorkflowRunner;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reprise parent avant ChildWorkflowCompleted : on ne doit pas rappeler dispatchNewWorkflowRun pour le même enfant
 * (sinon explosion de messages Messenger).
 *
 * @internal
 *
 * @coversNothing
 */
final class AsyncMessengerChildDoesNotRedispatchTest extends TestCase
{
    #[Test]
    public function scheduledAsyncChildWithoutCompletionDoesNotTriggerSecondDispatch(): void
    {
        $parentId = '01900000-0000-7000-8000-0000000000a1';
        $childId = '01900000-0000-7000-8000-0000000000b1';

        $dispatcher = new class implements WorkflowResumeDispatcher {
            public int $newWorkflowRunDispatches = 0;

            public function dispatchResume(string $executionId): void
            {
            }

            public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
            {
                ++$this->newWorkflowRunDispatches;
            }
        };

        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted($parentId));
        $store->append(new ChildWorkflowScheduled(
            $parentId,
            $childId,
            'ChildMini',
            ['n' => 4],
            ParentClosePolicy::Terminate,
            null,
            [],
        ));

        $transport = new InMemoryActivityTransport();
        $runtime = new ExecutionRuntime($store, $transport, new RegistryActivityExecutor(), 0, null, false);
        /** @psalm-suppress InternalClass implémentation en mémoire pour le test unitaire */
        $childLinkStore = new InMemoryChildWorkflowParentLinkStore();
        $childRunner = new ChildWorkflowRunner(
            $store,
            $runtime,
            new WorkflowRegistry(),
            new RegistryActivityExecutor(),
            0,
            true,
            $dispatcher,
            $childLinkStore,
        );

        $context = new ExecutionContext($parentId, $store, $transport, $childRunner);
        $context->executeChildWorkflow('ChildMini', ['n' => 4]);

        self::assertSame(0, $dispatcher->newWorkflowRunDispatches, 'enfant déjà planifié dans le journal : pas de second envoi Messenger');
    }
}
