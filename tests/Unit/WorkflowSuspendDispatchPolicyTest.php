<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Unit;

use Gplanchat\Durable\Awaitable\AnyAwaitable;
use Gplanchat\Durable\Awaitable\CancellingAnyAwaitable;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ExecutionRuntime::class)]
final class WorkflowSuspendDispatchPolicyTest extends TestCase
{
    private function distributedRuntime(): array
    {
        $store = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $runtime = new ExecutionRuntime($store, $transport, $executor, 0, null, true);

        return [$store, $transport, $executor, $runtime];
    }

    #[Test]
    public function activitySuspensionRequestsAutomaticResumeDispatch(): void
    {
        [$store, $transport, $executor, $runtime] = $this->distributedRuntime();
        $executor->register('noop', static fn () => null);
        $ctx = new ExecutionContext('ex-1', $store, $transport);
        $a = $ctx->activity('noop', []);

        try {
            $runtime->await($a, $ctx);
            self::fail('WorkflowSuspendedException attendue');
        } catch (WorkflowSuspendedException $e) {
            self::assertTrue($e->shouldDispatchResume(), 'worker activité / reprise file');
        }
    }

    #[Test]
    public function signalSuspensionDoesNotRequestAutomaticResumeDispatch(): void
    {
        [$store, $transport, , $runtime] = $this->distributedRuntime();
        $ctx = new ExecutionContext('ex-2', $store, $transport);
        $a = $ctx->waitSignal('go');

        try {
            $runtime->await($a, $ctx);
            self::fail('WorkflowSuspendedException attendue');
        } catch (WorkflowSuspendedException $e) {
            self::assertFalse($e->shouldDispatchResume(), 'livraison via DeliverWorkflowSignalMessage');
        }
    }

    #[Test]
    public function timerSuspensionRequestsAutomaticResumeDispatch(): void
    {
        [$store, $transport, , $runtime] = $this->distributedRuntime();
        $ctx = new ExecutionContext('ex-3', $store, $transport);
        $a = $ctx->timer(60.0);

        try {
            $runtime->await($a, $ctx);
            self::fail('WorkflowSuspendedException attendue');
        } catch (WorkflowSuspendedException $e) {
            self::assertTrue($e->shouldDispatchResume());
        }
    }

    #[Test]
    public function anyWithOnlyActivitiesRequestsResumeDispatch(): void
    {
        [$store, $transport, $executor, $runtime] = $this->distributedRuntime();
        $executor->register('a', static fn () => 1);
        $executor->register('b', static fn () => 2);
        $ctx = new ExecutionContext('ex-4', $store, $transport);
        $first = $ctx->activity('a', []);
        $second = $ctx->activity('b', []);
        $inner = new AnyAwaitable([$first, $second]);
        $composite = new CancellingAnyAwaitable($ctx, $inner, [$first, $second]);

        try {
            $runtime->await($composite, $ctx);
            self::fail('WorkflowSuspendedException attendue');
        } catch (WorkflowSuspendedException $e) {
            self::assertTrue($e->shouldDispatchResume());
        }
    }
}
