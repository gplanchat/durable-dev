<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Exception\DurableUpdateFailedException;
use Gplanchat\Durable\Exception\UpdateFailureReason;
use Gplanchat\Durable\Exception\WorkflowResultTimeout;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\WorkflowStartOptions;
use Gplanchat\DurableModule\Runtime\WaitingWorkflowClient;
use PHPUnit\Framework\TestCase;

/**
 * On the database backend a worker in another process runs the workflow, so the answer to a
 * result or an update is not there when the call returns: the client waits for it.
 */
final class TheDatabaseClientWaitsForAWorkerTest extends TestCase
{
    private InMemoryEventStore $events;
    private ExecutionId $id;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->id = ExecutionId::fromString('exec-1');
    }

    public function testAResultTheWorkerGivesAfterTheFirstReadIsReturned(): void
    {
        $inner = $this->inner(result: static function (int $call): mixed {
            return $call < 3 ? throw new WorkflowResultTimeout('exec-1', Duration::seconds(1)) : 'done';
        });

        self::assertSame('done', $this->client($inner)->result($this->id, Duration::seconds(5)));
    }

    public function testAResultThatNeverComesFailsWhenTheBoundElapses(): void
    {
        $inner = $this->inner(result: static fn(int $call): mixed => throw new WorkflowResultTimeout('exec-1', Duration::seconds(1)));

        $this->expectException(WorkflowResultTimeout::class);
        $this->client($inner)->result($this->id, Duration::milliseconds(30));
    }

    public function testAnUpdateTheWorkerAnswersLaterReturnsTheResultCarryingItsOwnId(): void
    {
        $inner = $this->inner(update: function (): mixed {
            // Another caller's update, then ours: only ours answers.
            $this->events->append(new WorkflowUpdateHandled($this->id, 'change', [], 'other', updateId: 'u-1'));
            $this->events->append(new WorkflowUpdateHandled($this->id, 'change', [], 'mine', updateId: 'u-2'));

            throw DurableUpdateFailedException::notHandled('change');
        });

        self::assertSame('mine', $this->client($inner)->update($this->id, 'change', [], 'u-2'));
    }

    public function testAnUpdateNobodyAnswersFailsAsNotHandledOnceTheWaitElapses(): void
    {
        $inner = $this->inner(update: static fn(): mixed => throw DurableUpdateFailedException::notHandled('change'));

        try {
            $this->client($inner)->update($this->id, 'change', [], 'u-1');
            self::fail('An update nobody handles must fail.');
        } catch (DurableUpdateFailedException $e) {
            self::assertSame(UpdateFailureReason::NotHandled, $e->reason);
        }
    }

    public function testAnUpdateIsSentOnce(): void
    {
        $sent = 0;
        $inner = $this->inner(update: function () use (&$sent): mixed {
            ++$sent;
            $this->events->append(new WorkflowUpdateHandled($this->id, 'change', [], 'ok', updateId: 'u-1'));

            throw DurableUpdateFailedException::notHandled('change');
        });

        $this->client($inner)->update($this->id, 'change', [], 'u-1');

        self::assertSame(1, $sent);
    }

    private function client(WorkflowClientPort $inner): WaitingWorkflowClient
    {
        return new WaitingWorkflowClient($inner, $this->events, Duration::milliseconds(30), 1_000);
    }

    /**
     * @param (\Closure(int): mixed)|null $result
     * @param (\Closure(): mixed)|null    $update
     */
    private function inner(?\Closure $result = null, ?\Closure $update = null): WorkflowClientPort
    {
        return new class ($result, $update) implements WorkflowClientPort {
            private int $results = 0;

            public function __construct(private readonly ?\Closure $result, private readonly ?\Closure $update) {}

            public function start(ExecutionId $executionId, string $workflowType, array $input = [], ?WorkflowStartOptions $options = null): ExecutionId
            {
                return $executionId;
            }

            public function signal(ExecutionId $executionId, string $name, array $arguments = [], ?string $requestId = null): void {}

            public function query(ExecutionId $executionId, string $name, array $arguments = []): mixed
            {
                return null;
            }

            public function update(ExecutionId $executionId, string $name, array $arguments = [], ?string $updateId = null): mixed
            {
                return ($this->update ?? static fn(): mixed => null)();
            }

            public function result(ExecutionId $executionId, ?Duration $timeout = null): mixed
            {
                return ($this->result ?? static fn(): mixed => null)(++$this->results);
            }

            public function cancel(ExecutionId $executionId, ?string $requestId = null): void {}

            public function terminate(ExecutionId $executionId, string $reason = ''): void {}
        };
    }
}
