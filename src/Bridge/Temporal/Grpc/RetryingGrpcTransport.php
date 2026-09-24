<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\Http\GrpcWire;
use Temporal\Api\Workflowservice\V1\UpdateWorkflowExecutionRequest;

/**
 * Retries a call that met DEADLINE_EXCEEDED, RESOURCE_EXHAUSTED or UNAVAILABLE, with capped
 * exponential backoff and full jitter, so a frontend restart does not end every worker loop.
 *
 * Only the RPCs below are retried. A deadline may expire after the server applied the call, so
 * a mutating RPC is retried only when a second application cannot happen: its request_id is
 * stamped once per call and reused by every attempt (the server dedupes on it), or its task
 * token makes the second one a NOT_FOUND, or an update carries its update_id. Any other RPC is
 * sent once.
 *
 * No retry starts past $budgetMs from the first attempt, backoff included: a call that used its
 * whole deadline is not sent again, only the ones that failed fast (connection refused, frontend
 * restarting).
 *
 * @see https://github.com/gplanchat/durable-dev/issues/353
 */
final class RetryingGrpcTransport implements GrpcTransport
{
    private const TRANSIENT = [GrpcWire::DEADLINE_EXCEEDED, self::RESOURCE_EXHAUSTED, GrpcWire::UNAVAILABLE];

    private const RESOURCE_EXHAUSTED = 8;

    /** Reads and polls: nothing to apply twice. */
    private const READS = [
        'CountActivityExecutions', 'DescribeActivityExecution', 'DescribeWorkflowExecution',
        'GetWorkflowExecutionHistory', 'ListActivityExecutions', 'ListWorkflowExecutions',
        'PollActivityExecution', 'PollActivityTaskQueue', 'PollNexusTaskQueue',
        'PollWorkflowExecutionUpdate', 'PollWorkflowTaskQueue', 'QueryWorkflow',
    ];

    /** Deduped by the server on request_id. */
    private const WITH_REQUEST_ID = [
        'RequestCancelWorkflowExecution', 'SignalWithStartWorkflowExecution',
        'SignalWorkflowExecution', 'StartWorkflowExecution',
    ];

    /** Bound to a task token: once applied, the token is spent and a replay gets NOT_FOUND. */
    private const WITH_TASK_TOKEN = [
        'RecordActivityTaskHeartbeat', 'RespondActivityTaskCanceled', 'RespondActivityTaskCompleted',
        'RespondActivityTaskFailed', 'RespondNexusTaskCompleted', 'RespondNexusTaskFailed',
        'RespondWorkflowTaskCompleted', 'RespondWorkflowTaskFailed',
    ];

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(int): void)|null $sleep milliseconds; injected by tests
     * @param (\Closure(): int)|null     $clock milliseconds; injected by tests
     */
    public function __construct(
        private readonly GrpcTransport $inner,
        private readonly int $maxAttempts = 10,
        private readonly int $baseDelayMs = 200,
        private readonly int $maxDelayMs = 5000,
        private readonly int $budgetMs = 30_000,
        ?\Closure $sleep = null,
        ?\Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        $this->clock = $clock ?? static fn(): int => intdiv(hrtime(true), 1_000_000);
    }

    /**
     * @template T of Message
     *
     * @param class-string<T>             $responseClass
     * @param array<string, list<string>> $metadata
     *
     * @return T
     */
    public function unary(string $method, Message $request, string $responseClass, array $metadata, ?int $timeoutMs): Message
    {
        $rpc = substr($method, (int) strrpos($method, '/') + 1);
        $retryable = \in_array($rpc, self::READS, true) || \in_array($rpc, self::WITH_TASK_TOKEN, true)
            // Deduped on update_id, which the caller draws: without one there is nothing to dedupe on.
            || ($request instanceof UpdateWorkflowExecutionRequest && '' !== (string) $request->getRequest()?->getMeta()?->getUpdateId());
        if (\in_array($rpc, self::WITH_REQUEST_ID, true)) {
            $retryable = true;
            if (method_exists($request, 'getRequestId') && method_exists($request, 'setRequestId') && '' === $request->getRequestId()) {
                $request->setRequestId(bin2hex(random_bytes(16)));
            }
        }

        $start = ($this->clock)();
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->inner->unary($method, $request, $responseClass, $metadata, $timeoutMs);
            } catch (\RuntimeException $e) {
                // The whole backoff window counts, not the delay drawn from it: jitter must not
                // decide whether a retry starts after the budget.
                $window = min($this->maxDelayMs, $this->baseDelayMs << ($attempt - 1));
                if (!$retryable || $attempt >= $this->maxAttempts || !\in_array($e->getCode(), self::TRANSIENT, true)
                    || ($this->clock)() - $start + $window >= $this->budgetMs) {
                    throw $e;
                }
                ($this->sleep)(random_int(0, $window));
            }
        }
    }
}
