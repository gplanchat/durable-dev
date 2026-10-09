<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\Exception\ExceptionInterface;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowRepositoryNotDeclared;
use Gplanchat\Durable\Exception\WorkflowTypeMismatch;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\WorkflowRepository;
use PHPUnit\Framework\TestCase;

#[AsWorkflow('order')]
final class RepositoryTestOrderWorkflow
{
    #[AsWorkflowMethod]
    public function run(): void {}

    #[AsSignalMethod('pay')]
    public function pay(): void {}
}

/** @extends WorkflowRepository<RepositoryTestOrderWorkflow> */
#[AsWorkflowRepository(RepositoryTestOrderWorkflow::class)]
final class RepositoryTestOrders extends WorkflowRepository {}

/** @extends WorkflowRepository<RepositoryTestOrderWorkflow> */
final class RepositoryTestUndeclared extends WorkflowRepository {}

/**
 * `create()` contacts no backend; `get()` asks the run catalogue and fails at once (#974).
 */
final class TheWorkflowRepositoryHandsOutStubsTest extends TestCase
{
    private InMemoryWorkflowRunCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new InMemoryWorkflowRunCatalog(new InMemoryEventStore());
    }

    public function testCreateKeepsTheIdTheCallerGives(): void
    {
        $id = ExecutionId::fromString('order-1');

        self::assertTrue($id->equals((new RepositoryTestOrders($this->catalog))->create($id)->executionId()));
    }

    public function testCreateGeneratesAnIdWhenNoneIsGiven(): void
    {
        $orders = new RepositoryTestOrders($this->catalog);

        self::assertFalse($orders->create()->executionId()->equals($orders->create()->executionId()));
    }

    public function testGetReturnsAStubForAStartedExecution(): void
    {
        $id = ExecutionId::fromString('order-2');
        $this->catalog->recordStart($id, 'order');

        self::assertTrue($id->equals((new RepositoryTestOrders($this->catalog))->get($id)->executionId()));
    }

    public function testGetFailsAtOnceForAnUnknownExecution(): void
    {
        try {
            (new RepositoryTestOrders($this->catalog))->get(ExecutionId::fromString('nope'));
            self::fail('get() must fail for an unknown execution.');
        } catch (WorkflowExecutionNotFound $e) {
            self::assertSame('nope', $e->executionId);
            self::assertInstanceOf(ExceptionInterface::class, $e);
        }
    }

    public function testGetFailsForAnExecutionOfAnotherWorkflow(): void
    {
        $this->catalog->recordStart(ExecutionId::fromString('inv-1'), 'invoice');

        try {
            (new RepositoryTestOrders($this->catalog))->get(ExecutionId::fromString('inv-1'));
            self::fail('get() must fail for another workflow type.');
        } catch (WorkflowTypeMismatch $e) {
            self::assertSame('inv-1', $e->executionId);
            self::assertSame('order', $e->expectedType);
            self::assertSame('invoice', $e->actualType);
        }
    }

    public function testARepositoryWithoutTheAttributeIsRefused(): void
    {
        $this->expectException(WorkflowRepositoryNotDeclared::class);

        (new RepositoryTestUndeclared($this->catalog))->create();
    }
}
