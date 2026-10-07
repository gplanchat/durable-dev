<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * #682: a workflow reads its own id as an `ExecutionId`, the type every port takes.
 */
final class TheWorkflowReadsItsExecutionIdTest extends TestCase
{
    public function testTheEnvironmentGivesAnExecutionId(): void
    {
        $runner = new InMemoryWorkflowRunner(new InMemoryEventStore(), new InMemoryActivityTransport(), new RegistryActivityExecutor());

        $id = $runner->run(ExecutionId::fromString('exec-1'), static fn(WorkflowEnvironment $env): ExecutionId => $env->executionId());

        self::assertInstanceOf(ExecutionId::class, $id);
        self::assertSame('exec-1', $id->toString());
    }
}
