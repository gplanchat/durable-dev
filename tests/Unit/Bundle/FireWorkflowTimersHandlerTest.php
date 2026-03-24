<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Unit\Bundle;

use Gplanchat\Durable\Bundle\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Tests\Support\CallbackWorkflowResumeDispatcher;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FireWorkflowTimersHandler::class)]
final class FireWorkflowTimersHandlerTest extends TestCase
{
    #[Test]
    public function appendsTimerCompletedAndDispatchesResumeWhenDue(): void
    {
        $store = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $runtime = new ExecutionRuntime(
            $store,
            $transport,
            $executor,
            0,
            static fn (): float => \PHP_FLOAT_MAX,
            false,
        );

        $executionId = 'timer-wake-1';
        $store->append(new ExecutionStarted($executionId));
        $store->append(new TimerScheduled($executionId, 't1', 0.0));

        $resumes = [];
        $handler = new FireWorkflowTimersHandler(
            $store,
            $runtime,
            new CallbackWorkflowResumeDispatcher(static function (string $id) use (&$resumes): void {
                $resumes[] = $id;
            }),
        );

        $handler->__invoke(new FireWorkflowTimersMessage($executionId));

        self::assertSame([$executionId], $resumes);
        $hasCompleted = false;
        foreach ($store->readStream($executionId) as $e) {
            if ($e instanceof TimerCompleted && 't1' === $e->timerId()) {
                $hasCompleted = true;
            }
        }
        self::assertTrue($hasCompleted);
    }

    #[Test]
    public function doesNotDispatchResumeWhenNoTimerFires(): void
    {
        $store = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $runtime = new ExecutionRuntime(
            $store,
            $transport,
            $executor,
            0,
            static fn (): float => 0.0,
            false,
        );

        $executionId = 'timer-wake-2';
        $store->append(new ExecutionStarted($executionId));
        $store->append(new TimerScheduled($executionId, 't2', 999999.0));

        $resumes = [];
        $handler = new FireWorkflowTimersHandler(
            $store,
            $runtime,
            new CallbackWorkflowResumeDispatcher(static function (string $id) use (&$resumes): void {
                $resumes[] = $id;
            }),
        );

        $handler->__invoke(new FireWorkflowTimersMessage($executionId));

        self::assertSame([], $resumes);
    }
}
