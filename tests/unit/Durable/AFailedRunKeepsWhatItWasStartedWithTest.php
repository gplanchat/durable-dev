<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * A run that fails keeps the metadata row that says what it was started with.
 *
 * The row exists for the re-dispatch, and a failed run is never re-dispatched — which is why it was
 * deleted. But it is also the only place the start payload lives on a backend where a dispatched run
 * writes no `ExecutionStarted`: the journal keeps the events, not the input. Deleting it destroys
 * the one record of what the execution was given, exactly for the runs an operator most wants to
 * look at.
 *
 * The success path already settled the question the other way: it calls `markCompleted()` and keeps
 * the row "so that the type stays consultable (profiler, observability)". A failure deserves that at
 * least as much. `hasActiveWorkflowMetadata()` reports a completed row as inactive, so resumes keep
 * ignoring it either way — nothing is re-delivered.
 *
 * Found downstream: an agent conversation is a workflow execution whose start payload carries who
 * owns it. When the run failed, the owner vanished with the row and the reader was refused their own
 * conversation.
 */
final class AFailedRunKeepsWhatItWasStartedWithTest extends TestCase
{
    public function testAFailedRunKeepsItsStartPayload(): void
    {
        $metadata = new InMemoryWorkflowMetadataStore();
        $metadata->save('exec-failed', ThrowingWorkflow::class, ['owner' => 'ada']);

        try {
            ($this->handlerFor($metadata))(new ResumeWorkflowMessage('exec-failed'));
            self::fail('The workflow was supposed to fail.');
        } catch (\RuntimeException) {
            // The failure still propagates: that is not what changes.
        }

        $row = $metadata->get('exec-failed');

        self::assertNotNull($row, 'A failed run lost the only record of what it was started with.');
        self::assertSame(['owner' => 'ada'], $row['payload']);
        self::assertTrue($row['completed'] ?? false, 'Kept, but marked finished: no resume may pick it up again.');
        self::assertFalse($metadata->hasActiveWorkflowMetadata('exec-failed'));
    }

    private function handlerFor(InMemoryWorkflowMetadataStore $metadata): ResumeWorkflowHandler
    {
        $store = new InMemoryEventStore();
        $registry = new WorkflowRegistry();
        $registry->registerClass(ThrowingWorkflow::class);

        return new ResumeWorkflowHandler(
            new ExecutionEngine(
                $store,
                new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
            ),
            $registry,
            $metadata,
            new NullWorkflowResumeDispatcher(),
            $store,
            new InMemoryChildWorkflowParentLinkStore(),
            new SilentTimerDispatcher(),
            new WorkflowDefinitionLoader(),
        );
    }
}

final class SilentTimerDispatcher implements WorkflowTimerDispatcher
{
    public function dispatchTimerFire(string $executionId, int $delayMs = 0): void {}
}

#[AsWorkflow(name: 'test.throwing')]
final class ThrowingWorkflow
{
    #[AsWorkflowMethod]
    public function run(string $owner = ''): string
    {
        throw new \RuntimeException('the workflow could not finish');
    }
}
