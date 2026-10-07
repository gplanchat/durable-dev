<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Transport\AwaitedFact;
use Psr\Log\LoggerInterface;

/**
 * The start of a run without a cluster (#976): the in-memory journal lives in this process, so the
 * process that starts a run executes it, in `dispatchNewWorkflowRun()`, before it returns.
 *
 * `MagentoRuntime::run()` does the work and bounds it with `budgetSeconds`. As on Temporal, where
 * the dispatch only starts the run, a run that fails does not throw from the dispatch: the failure
 * goes to the logger. Only a start error throws, an undeclared workflow.
 * The resumes are no-ops: nothing else advances a run, and `run()` already drove it to its end.
 *
 * ponytail: it blocks the request, as `run()` does, up to `budgetSeconds`. That is the named
 * difference with Temporal (see the backends page). A web request that must not wait needs the
 * cluster (`durable/temporal/dsn`). The in-memory journal ends with the request, so the log line
 * is the only trace of a failure that remains.
 */
final class InProcessWorkflowResumeDispatcher implements WorkflowResumeDispatcher
{
    public function __construct(
        private readonly MagentoRuntime $runtime,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void {}

    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void {}

    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        /** @var class-string $workflowType */
        try {
            $this->runtime->run($workflowType, $payload, $executionId->toString());
        } catch (UndeclaredWorkflowException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logger?->error(sprintf('Workflow "%s" (%s) failed: %s', $executionId->toString(), $workflowType, $exception->getMessage()), ['exception' => $exception]);
        }
    }
}
