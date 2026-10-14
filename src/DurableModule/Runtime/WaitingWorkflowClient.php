<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Exception\DurableUpdateFailedException;
use Gplanchat\Durable\Exception\UpdateFailureReason;
use Gplanchat\Durable\Exception\WorkflowResultTimeout;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\WorkflowStartOptions;

/**
 * The port on the database backend. A worker in another process runs the workflow, so the answer
 * to a result or an update is not in the journal when the first read happens: this client waits
 * for it, and delegates every other operation.
 *
 * `result()` reads again until the bound elapses. `update()` sends the update once, then reads the
 * journal for the {@see WorkflowUpdateHandled} that carries its id; an update no worker answers
 * within `$updateWait` fails as not handled. A worker that is not running is not told apart from
 * a slow one.
 */
final class WaitingWorkflowClient implements WorkflowClientPort
{
    public function __construct(
        private readonly WorkflowClientPort $inner,
        private readonly EventStoreInterface $events,
        private readonly Duration $updateWait,
        private readonly int $pollMicroseconds = 100_000,
    ) {}

    public function start(ExecutionId $executionId, string $workflowType, array $input = [], ?WorkflowStartOptions $options = null): ExecutionId
    {
        return $this->inner->start($executionId, $workflowType, $input, $options);
    }

    public function signal(ExecutionId $executionId, string $name, array $arguments = [], ?string $requestId = null): void
    {
        $this->inner->signal($executionId, $name, $arguments, $requestId);
    }

    public function query(ExecutionId $executionId, string $name, array $arguments = []): mixed
    {
        return $this->inner->query($executionId, $name, $arguments);
    }

    public function update(ExecutionId $executionId, string $name, array $arguments = [], ?string $updateId = null): mixed
    {
        $updateId ??= bin2hex(random_bytes(16));

        try {
            return $this->inner->update($executionId, $name, $arguments, $updateId);
        } catch (DurableUpdateFailedException $notYet) {
            if (UpdateFailureReason::NotHandled !== $notYet->reason) {
                throw $notYet;
            }
        }

        $startedAt = microtime(true);
        do {
            foreach ($this->events->readStream($executionId) as $event) {
                if ($event instanceof WorkflowUpdateHandled && $event->updateId() === $updateId) {
                    if (null !== $event->failure()) {
                        throw new DurableUpdateFailedException($name, $event->failure()->message);
                    }

                    return $event->result();
                }
            }
            usleep($this->pollMicroseconds);
        } while (!$this->updateWait->hasElapsedSince($startedAt, microtime(true)));

        throw DurableUpdateFailedException::notHandled($name);
    }

    public function result(ExecutionId $executionId, ?Duration $timeout = null): mixed
    {
        $startedAt = microtime(true);
        while (true) {
            try {
                return $this->inner->result($executionId, $timeout);
            } catch (WorkflowResultTimeout $notYet) {
                if (null !== $timeout && $timeout->hasElapsedSince($startedAt, microtime(true))) {
                    throw $notYet;
                }
            }
            usleep($this->pollMicroseconds);
        }
    }

    public function cancel(ExecutionId $executionId, ?string $requestId = null): void
    {
        $this->inner->cancel($executionId, $requestId);
    }

    public function terminate(ExecutionId $executionId, string $reason = ''): void
    {
        $this->inner->terminate($executionId, $reason);
    }
}
