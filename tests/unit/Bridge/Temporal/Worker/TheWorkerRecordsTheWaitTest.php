<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowLifecycle;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\Deferred;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Enums\V1\CommandType;

/**
 * #514: on Temporal no journal of ours records what a suspended run waits on, so each suspending
 * task writes it in the `durableWaitingOn` memo, which the run list reads back for free.
 */
final class TheWorkerRecordsTheWaitTest extends TestCase
{
    public function testASuspendingTaskWritesTheWaitInTheMemo(): void
    {
        $buffer = $this->buffer();
        $lifecycle = new TemporalWorkflowLifecycle($buffer, describeWait: static fn(Awaitable $pending): string => 'timer due at 2026-09-24T10:00:00+00:00');

        $lifecycle->onSuspended(ExecutionId::fromString('exec-1'), (new Deferred())->awaitable());

        self::assertSame('timer due at 2026-09-24T10:00:00+00:00', self::waitIn($buffer->peek()));
    }

    public function testAWaitWithoutWordsClearsThePreviousOne(): void
    {
        $buffer = $this->buffer();
        $buffer->recordWait(null);

        self::assertSame(CommandType::COMMAND_TYPE_MODIFY_WORKFLOW_PROPERTIES, $buffer->peek()[0]->getCommandType());
        self::assertNull(self::waitIn($buffer->peek()), 'a stale wait sends the operator to the wrong place');
    }

    public function testWithoutADescriberNothingIsWritten(): void
    {
        $buffer = $this->buffer();

        (new TemporalWorkflowLifecycle($buffer))->onSuspended(ExecutionId::fromString('exec-1'), (new Deferred())->awaitable());

        self::assertSame([], $buffer->peek());
    }

    private function buffer(): TemporalWorkflowCommandBuffer
    {
        return new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), ExecutionId::fromString('exec-1'));
    }

    /**
     * @param list<Command> $commands
     */
    private static function waitIn(array $commands): ?string
    {
        $field = $commands[0]->getModifyWorkflowPropertiesCommandAttributes()?->getUpsertedMemo()?->getFields()[JournalExecutionIdResolver::MEMO_KEY_DURABLE_WAITING_ON] ?? null;
        self::assertNotNull($field, 'the wait travels in the durableWaitingOn memo');

        $waitingOn = JsonPlainPayload::decode($field);
        self::assertTrue(null === $waitingOn || \is_string($waitingOn));

        return $waitingOn;
    }
}
