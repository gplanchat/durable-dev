<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\Exception\ExceptionInterface;
use Gplanchat\Durable\Exception\WorkflowClassNotFound;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowRepositoryNotDeclared;
use Gplanchat\Durable\Exception\WorkflowTypeMismatch;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
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
class RepositoryTestOrders extends WorkflowRepository {}

/** @extends WorkflowRepository<RepositoryTestOrderWorkflow> */
final class RepositoryTestUndeclared extends WorkflowRepository {}

final class RepositoryTestSubOrders extends RepositoryTestOrders {}

/** @extends WorkflowRepository<object> */
#[AsWorkflowRepository(RepositoryTestMissingWorkflow::WORKFLOW)] // @phpstan-ignore argument.type (a class that does not exist: deliberate)
final class RepositoryTestMissingWorkflow extends WorkflowRepository
{
    /** A name no autoloader resolves. */
    public const WORKFLOW = 'App\\NoSuchWorkflow';
}

/** A class without `#[AsWorkflow]`: its type is its short name. */
final class RepositoryTestPlainClass {}

/** @extends WorkflowRepository<RepositoryTestPlainClass> */
#[AsWorkflowRepository(RepositoryTestPlainClass::class)]
final class RepositoryTestPlain extends WorkflowRepository {}

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

    public function testTheConstructorIsFinalSoASubclassCannotWidenIt(): void
    {
        self::assertTrue((new \ReflectionMethod(WorkflowRepository::class, '__construct'))->isFinal());
    }

    public function testAnAttributeIsNotInheritedByASubclass(): void
    {
        $this->expectException(WorkflowRepositoryNotDeclared::class);

        (new RepositoryTestSubOrders($this->catalog))->create();
    }

    public function testACatalogueReportingUnknownWorkflowIsATypeMismatch(): void
    {
        $catalog = $this->createStub(WorkflowRunCatalogInterface::class);
        $catalog->method('findRun')->willReturn(
            new WorkflowRunDescription('ord-9', 'UnknownWorkflow', WorkflowRunStatus::Running),
        );

        try {
            (new RepositoryTestOrders($catalog))->get(ExecutionId::fromString('ord-9'));
            self::fail('get() must fail when the catalogue reports another type.');
        } catch (WorkflowTypeMismatch $e) {
            self::assertSame('order', $e->expectedType);
            self::assertSame('UnknownWorkflow', $e->actualType);
        }
    }

    public function testAWorkflowClassThatDoesNotExistIsRefusedWithItsCause(): void
    {
        $this->catalog->recordStart(ExecutionId::fromString('x-1'), 'whatever');

        try {
            (new RepositoryTestMissingWorkflow($this->catalog))->get(ExecutionId::fromString('x-1'));
            self::fail('A missing workflow class must be refused.');
        } catch (WorkflowClassNotFound $e) {
            self::assertSame(RepositoryTestMissingWorkflow::WORKFLOW, $e->workflowClass);
            self::assertInstanceOf(\ReflectionException::class, $e->getPrevious());
            self::assertInstanceOf(ExceptionInterface::class, $e);
        }
    }

    public function testAClassWithoutAsWorkflowIsKnownByItsShortName(): void
    {
        $id = ExecutionId::fromString('p-1');
        $this->catalog->recordStart($id, 'RepositoryTestPlainClass');

        self::assertTrue($id->equals((new RepositoryTestPlain($this->catalog))->get($id)->executionId()));
    }
}
