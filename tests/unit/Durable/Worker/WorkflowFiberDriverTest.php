<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Worker;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\WorkflowFiberDriver;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Le pilotage du fiber existait en double (ExecutionEngine / WorkflowTaskRunner) avec des
 * chaînes de `catch` divergentes. Ce test verrouille la boucle unique : quelle que soit
 * l'implémentation du port, c'est le même hook qui est appelé pour la même issue.
 */
final class WorkflowFiberDriverTest extends TestCase
{
    public function testHandlerGoingAllTheWayReportsCompleted(): void
    {
        $lifecycle = $this->recorder();
        $result = $this->drive($lifecycle, static fn (WorkflowEnvironment $env): string => 'done');

        self::assertSame('done', $result);
        self::assertSame(['onBeforeRun', 'onCompleted'], $lifecycle->calls);
        self::assertSame('done', $lifecycle->completedResult);
    }

    public function testUnsettledAwaitableReportsSuspendedNotFailed(): void
    {
        $lifecycle = $this->recorder();
        $result = $this->drive($lifecycle, static fn (WorkflowEnvironment $env): mixed => $env->await($env->activity('never', [])));

        self::assertNull($result);
        self::assertSame(['onBeforeRun', 'onSuspended'], $lifecycle->calls);
    }

    public function testContinueAsNewIsANormalOutcomeNotAFailure(): void
    {
        $lifecycle = $this->recorder();
        $this->drive($lifecycle, static function (WorkflowEnvironment $env): never {
            throw new ContinueAsNewRequested('NextWorkflow', ['cursor' => 3]);
        });

        self::assertSame(['onBeforeRun', 'onContinuedAsNew'], $lifecycle->calls);
        self::assertSame('NextWorkflow', $lifecycle->continuation?->workflowType);
    }

    public function testUnhandledThrowableReportsFailed(): void
    {
        $lifecycle = $this->recorder();
        $this->drive($lifecycle, static function (WorkflowEnvironment $env): never {
            throw new \DomainException('boom');
        });

        self::assertSame(['onBeforeRun', 'onFailed'], $lifecycle->calls);
        self::assertSame('boom', $lifecycle->failure?->getMessage());
    }

    public function testOnBeforeRunCanPreventTheFiberFromStarting(): void
    {
        $lifecycle = new class implements WorkflowLifecycleInterface {
            public function onBeforeRun(string $executionId): void
            {
                throw new \RuntimeException('refused');
            }

            public function onCompleted(string $executionId, mixed $result): void
            {
                throw new \LogicException('must not run');
            }

            public function onSuspended(string $executionId, Awaitable $pending): void
            {
            }

            public function isCancellationPending(string $executionId): bool
            {
                return false;
            }

            public function onCancellationDelivered(string $executionId, array $cancelledOperationIds): void
            {
            }

            public function onCancelled(string $executionId, WorkflowCancelledFailure $failure): void
            {
            }

            public function onContinuedAsNew(string $executionId, ContinueAsNewRequested $request): void
            {
            }

            public function onFailed(string $executionId, \Throwable $failure): void
            {
                throw new \LogicException('must not run');
            }
        };

        $ran = false;
        $this->expectException(\RuntimeException::class);
        try {
            $this->drive($lifecycle, static function () use (&$ran): string {
                $ran = true;

                return 'x';
            });
        } finally {
            self::assertFalse($ran, 'le handler ne doit pas démarrer');
        }
    }

    // -------------------------------------------------------------------------

    private function drive(WorkflowLifecycleInterface $lifecycle, callable $handler): mixed
    {
        $store = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $runtime = new ExecutionRuntime($store, $transport, new RegistryActivityExecutor(), 0, null, true);
        $context = new ExecutionContext(
            'exec-1',
            new EventStoreHistorySource($store, 'exec-1'),
            new EventStoreCommandBuffer($store, $transport, 'exec-1'),
        );

        return (new WorkflowFiberDriver($lifecycle))->run(
            'exec-1',
            $context,
            new WorkflowEnvironment($context, $runtime),
            $handler,
        );
    }

    private function recorder(): object
    {
        return new class implements WorkflowLifecycleInterface {
            /** @var list<string> */
            public array $calls = [];
            public mixed $completedResult = null;
            public bool $cancellationPending = false;
            public ?WorkflowCancelledFailure $cancellation = null;
            public ?ContinueAsNewRequested $continuation = null;
            public ?\Throwable $failure = null;

            public function onBeforeRun(string $executionId): void
            {
                $this->calls[] = 'onBeforeRun';
            }

            public function onCompleted(string $executionId, mixed $result): void
            {
                $this->calls[] = 'onCompleted';
                $this->completedResult = $result;
            }

            public function onSuspended(string $executionId, Awaitable $pending): void
            {
                $this->calls[] = 'onSuspended';
            }

            public function isCancellationPending(string $executionId): bool
            {
                return $this->cancellationPending;
            }

            public function onCancellationDelivered(string $executionId, array $cancelledOperationIds): void
            {
                $this->calls[] = 'onCancellationDelivered';
            }

            public function onCancelled(string $executionId, WorkflowCancelledFailure $failure): void
            {
                $this->calls[] = 'onCancelled';
                $this->cancellation = $failure;
            }

            public function onContinuedAsNew(string $executionId, ContinueAsNewRequested $request): void
            {
                $this->calls[] = 'onContinuedAsNew';
                $this->continuation = $request;
            }

            public function onFailed(string $executionId, \Throwable $failure): void
            {
                $this->calls[] = 'onFailed';
                $this->failure = $failure;
            }
        };
    }
}
