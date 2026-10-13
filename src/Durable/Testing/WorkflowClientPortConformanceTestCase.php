<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowTypeMismatch;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\WorkflowRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Conformance suite for {@see WorkflowClientPort}: every backend subclasses it (openspec change
 * `workflow-repository`, task 1.1).
 *
 * This slice covers what needs no workflow body: start, a second start, an unknown execution on
 * every operation, an ended execution on cancel and terminate, and the type check of the
 * repository. Signal, query, update with result, result with a bound, and the cancel and terminate
 * that take effect need a workflow that waits and compensates; they join the suite with the
 * journal backends (tasks 2.1 to 2.5) and Temporal (3.1), with the hook they need.
 */
abstract class WorkflowClientPortConformanceTestCase extends TestCase
{
    abstract protected function clientUnderTest(): WorkflowClientPort;

    /**
     * The catalogue that sees what the client started.
     */
    abstract protected function catalogUnderTest(): WorkflowRunCatalogInterface;

    /**
     * Brings an execution started through the client to the given outcome.
     */
    abstract protected function endRun(ExecutionId $executionId, WorkflowRunStatus $outcome): void;

    public function testAStartedExecutionIsFoundInTheCatalogue(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE, ['a' => 1]);

        $run = $this->catalogUnderTest()->findRun($id);
        self::assertNotNull($run);
        self::assertSame(ConformanceClientWorkflow::TYPE, $run->workflowName);
        self::assertSame(WorkflowRunStatus::Running, $run->status);
    }

    public function testASecondStartFailsAndLeavesTheFirstExecutionAlone(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE, ['a' => 1]);

        try {
            $this->clientUnderTest()->start($id, 'other.type', ['a' => 2]);
            self::fail('A second start must fail.');
        } catch (WorkflowExecutionAlreadyStarted $e) {
            self::assertSame('exec-1', $e->executionId);
        }
        self::assertSame(ConformanceClientWorkflow::TYPE, $this->catalogUnderTest()->findRun($id)?->workflowName);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operations(): iterable
    {
        foreach (['signal', 'query', 'update', 'result', 'cancel', 'terminate'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('operations')]
    public function testAnUnknownExecutionFailsAtOnceOnEveryOperation(string $operation): void
    {
        $client = $this->clientUnderTest();
        $id = ExecutionId::fromString('exec-nobody');

        try {
            match ($operation) {
                'signal' => $client->signal($id, 'pay'),
                'query' => $client->query($id, 'status'),
                'update' => $client->update($id, 'change'),
                'result' => $client->result($id, Duration::seconds(0.1)),
                'cancel' => $client->cancel($id),
                'terminate' => $client->terminate($id),
                default => self::fail('Unknown operation.'),
            };
            self::fail('An unknown execution must fail.');
        } catch (WorkflowExecutionNotFound $e) {
            self::assertSame('exec-nobody', $e->executionId);
        }
    }

    public function testCancellingOrTerminatingAnEndedExecutionFails(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);
        $this->endRun($id, WorkflowRunStatus::Completed);

        foreach ([fn() => $this->clientUnderTest()->cancel($id), fn() => $this->clientUnderTest()->terminate($id)] as $call) {
            try {
                $call();
                self::fail('An ended execution cannot be cancelled or terminated.');
            } catch (WorkflowExecutionAlreadyEnded $e) {
                self::assertSame('exec-1', $e->executionId);
            }
        }
        self::assertSame(WorkflowRunStatus::Completed, $this->catalogUnderTest()->findRun($id)?->status);
    }

    public function testARepositoryRefusesAnExecutionOfAnotherWorkflowType(): void
    {
        $this->clientUnderTest()->start(ExecutionId::fromString('exec-1'), 'other.type');
        $repository = new #[AsWorkflowRepository(ConformanceClientWorkflow::class)] class ($this->catalogUnderTest()) extends WorkflowRepository {};

        $this->expectException(WorkflowTypeMismatch::class);
        $repository->get(ExecutionId::fromString('exec-1'));
    }

    public function testARepositoryFindsAnExecutionOfItsWorkflowType(): void
    {
        $this->clientUnderTest()->start(ExecutionId::fromString('exec-1'), ConformanceClientWorkflow::TYPE);
        $repository = new #[AsWorkflowRepository(ConformanceClientWorkflow::class)] class ($this->catalogUnderTest()) extends WorkflowRepository {};

        self::assertSame('exec-1', $repository->get(ExecutionId::fromString('exec-1'))->executionId()->toString());
    }
}
