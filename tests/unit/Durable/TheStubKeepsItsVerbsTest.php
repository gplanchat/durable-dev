<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsUpdateMethod;
use Gplanchat\Durable\Exception\ExceptionInterface;
use Gplanchat\Durable\Exception\ReservedStubMethodName;
use Gplanchat\Durable\Exception\WorkflowClassNotFound;
use Gplanchat\Durable\Workflow\ReservedStubMethods;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StubTestFine
{
    #[AsSignalMethod('pay')]
    public function pay(): void {}

    /** Not a signal, query or update: free to be named anything. */
    public function cancel(): void {}
}

final class StubTestCancelSignal
{
    #[AsSignalMethod('c')]
    public function Cancel(): void {}
}

final class StubTestResultQuery
{
    #[AsQueryMethod('r')]
    public function result(): int
    {
        return 1;
    }
}

final class StubTestExecuteUpdate
{
    #[AsUpdateMethod('e')]
    public function EXECUTE(): void {}
}

final class StubTestTerminateQuery
{
    #[AsQueryMethod('t')]
    public function TeRmInAtE(): void {}
}

final class StubTestStartUpdate
{
    #[AsUpdateMethod('s')]
    public function start(): void {}
}

final class StubTestExecutionIdSignal
{
    #[AsSignalMethod('i')]
    public function executionId(): void {}
}

/**
 * The stub's verbs share the namespace of the workflow's signal, query and update methods (#974).
 */
final class TheStubKeepsItsVerbsTest extends TestCase
{
    public function testAWorkflowWithoutAClashPasses(): void
    {
        ReservedStubMethods::assertFreeFor(StubTestFine::class);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function clashes(): iterable
    {
        yield 'signal, other case' => [StubTestCancelSignal::class, 'Cancel'];
        yield 'query' => [StubTestResultQuery::class, 'result'];
        yield 'query, mixed case' => [StubTestTerminateQuery::class, 'TeRmInAtE'];
        yield 'update, start' => [StubTestStartUpdate::class, 'start'];
        yield 'signal, executionId' => [StubTestExecutionIdSignal::class, 'executionId'];
        yield 'update, upper case' => [StubTestExecuteUpdate::class, 'EXECUTE'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('clashes')]
    public function testAClashIsRefusedNamingTheClassAndTheMethod(string $class, string $method): void
    {
        try {
            ReservedStubMethods::assertFreeFor($class);
            self::fail('A reserved name must be refused.');
        } catch (ReservedStubMethodName $e) {
            self::assertSame($class, $e->workflowClass);
            self::assertSame($method, $e->method);
            self::assertInstanceOf(ExceptionInterface::class, $e);
        }
    }

    public function testAClassThatDoesNotExistIsRefusedWithItsCause(): void
    {
        $missing = 'App\\NoSuchWorkflow';

        try {
            ReservedStubMethods::assertFreeFor($missing); // @phpstan-ignore argument.type (a class that does not exist: deliberate)
            self::fail('A missing class must be refused.');
        } catch (WorkflowClassNotFound $e) {
            self::assertSame($missing, $e->workflowClass);
            self::assertInstanceOf(\ReflectionException::class, $e->getPrevious());
        }
    }
}
