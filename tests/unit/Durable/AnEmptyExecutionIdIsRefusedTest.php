<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\FencedEventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * M8 (#329): an empty identifier names no execution, and every store would file it under "".
 *
 * The engine, the pass and the fiber driver take an `ExecutionId` (#682), so an empty id cannot
 * reach them. The wire messages keep a string: their handlers convert it first, and refuse an empty
 * one before any store hears of it, whether the store fences or not.
 */
final class AnEmptyExecutionIdIsRefusedTest extends TestCase
{
    public function testAnEmptyStringIsNotAnExecutionId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ExecutionId::fromString('');
    }

    public function testAnyOtherStringIs(): void
    {
        self::assertSame('exec-1', ExecutionId::fromString('exec-1')->toString());
    }

    public function testATimerMessageWithAnEmptyIdIsRefusedOverAFencedStore(): void
    {
        $store = $this->createMock(FencedEventStoreInterface::class);
        $store->expects(self::never())->method(self::anything());

        $this->expectException(\InvalidArgumentException::class);

        $this->timersHandler($store)(new FireWorkflowTimersMessage(''));
    }

    public function testATimerMessageWithAnEmptyIdIsRefusedOverAStoreThatCannotFence(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::never())->method(self::anything());

        $this->expectException(\InvalidArgumentException::class);

        $this->timersHandler($store)(new FireWorkflowTimersMessage(''));
    }

    public function testAResumeMessageWithAnEmptyIdIsRefusedBeforeAnyStoreHearsOfIt(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::never())->method(self::anything());
        $metadata = $this->createMock(WorkflowMetadataStore::class);
        $metadata->expects(self::never())->method(self::anything());

        $this->expectException(\InvalidArgumentException::class);

        (new ResumeWorkflowHandler(
            new ExecutionEngine($store, $this->runtime($store)),
            new WorkflowRegistry(),
            $metadata,
            $this->createStub(WorkflowResumeDispatcher::class),
            $store,
            $this->createStub(ChildWorkflowParentLinkStoreInterface::class),
            $this->createStub(WorkflowTimerDispatcher::class),
            new WorkflowDefinitionLoader(),
        ))(new ResumeWorkflowMessage(''));
    }

    private function timersHandler(EventStoreInterface $store): FireWorkflowTimersHandler
    {
        return new FireWorkflowTimersHandler(
            $store,
            $this->runtime($store),
            $this->createStub(WorkflowResumeDispatcher::class),
            $this->createStub(WorkflowTimerDispatcher::class),
        );
    }

    private function runtime(EventStoreInterface $store): ExecutionRuntime
    {
        return new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true);
    }
}
