<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\Exception\ExceptionInterface;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;
use PHPUnit\Framework\TestCase;

/**
 * DUR051: an inline child is not a Temporal concept, since the server writes a child's outcome
 * into the parent's history. The two methods that record one refuse by name instead of doing
 * nothing, so a wiring mistake that reached them would be an error rather than a lost outcome.
 */
final class TheBufferRefusesWhatTemporalCannotHonourTest extends TestCase
{
    public function testRecordingAnInlineChildOutcomeIsRefused(): void
    {
        $refusal = $this->refusalOf(static fn(TemporalWorkflowCommandBuffer $buffer) => $buffer->completeChildWorkflow(ExecutionId::fromString('child-1'), 'done'));

        self::assertStringContainsString('Temporal', $refusal->getMessage());
        self::assertStringContainsString('completeChildWorkflow', $refusal->getMessage());
    }

    public function testRecordingAnInlineChildFailureIsRefused(): void
    {
        $refusal = $this->refusalOf(static fn(TemporalWorkflowCommandBuffer $buffer) => $buffer->failChildWorkflow(ExecutionId::fromString('child-1'), new \RuntimeException('boom')));

        self::assertStringContainsString('failChildWorkflow', $refusal->getMessage());
    }

    /**
     * The context records an inline child's outcome inside the same `catch (\Throwable)` that
     * reports the child's own failure. A refusal must reach the caller as it was thrown, not be
     * reported as the child's failure (and refused a second time on the way).
     */
    public function testARefusalReachesTheCallerUnchanged(): void
    {
        $inline = new class implements ChildWorkflowRunnerInterface {
            public function defersChildStart(): bool
            {
                return false;
            }

            public function runChild(ExecutionId $childExecutionId, string $workflowType, array $input, ?ExecutionId $parentExecutionId = null): mixed
            {
                return 'done';
            }
        };
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'exec-1');
        $context = new ExecutionContext('exec-1', TemporalExecutionHistory::fromEvents([]), $buffer, $inline);

        $this->expectException(UnsupportedByBackendException::class);
        $this->expectExceptionMessage('completeChildWorkflow');

        $context->executeChildWorkflow('ChildType');
    }

    public function testARefusalIsADurableError(): void
    {
        self::assertInstanceOf(ExceptionInterface::class, UnsupportedByBackendException::forMethod('Temporal', 'x', 'y'));
    }

    /**
     * @param \Closure(TemporalWorkflowCommandBuffer): mixed $call
     */
    private function refusalOf(\Closure $call): UnsupportedByBackendException
    {
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'exec-1');

        try {
            $call($buffer);
        } catch (UnsupportedByBackendException $refusal) {
            self::assertSame([], $buffer->peek(), 'a refusal emits no command');

            return $refusal;
        }

        self::fail('the call must be refused');
    }
}
