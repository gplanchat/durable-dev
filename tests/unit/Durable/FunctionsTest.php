<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use integration\Gplanchat\Durable\Support\DurableTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
#[CoversClass(ExecutionEngine::class)]
#[CoversClass(ExecutionRuntime::class)]
#[CoversClass(WorkflowEnvironment::class)]
final class FunctionsTest extends DurableTestCase
{
    #[Test]
    public function asyncReturnsResolvedAwaitable(): void
    {
        $stack = $this->stack();
        $ctx = new ExecutionContext(
            $this->executionId(),
            $stack['eventStore'],
            $stack['activityTransport'],
        );
        $env = WorkflowEnvironment::wrap($ctx, $stack['runtime']);

        $a = $env->async(42);
        self::assertTrue($a->isSettled());
        self::assertSame(42, $a->getResult());
    }

    #[Test]
    public function parallelAwaitsAllAndReturnsOrderedResults(): void
    {
        $stack = $this->stack();
        $activityExecutor = $stack['activityExecutor'];
        $activityExecutor->register('a', fn () => 1);
        $activityExecutor->register('b', fn () => 2);
        $activityExecutor->register('c', fn () => 3);

        $engine = new ExecutionEngine($stack['eventStore'], $stack['runtime']);
        $executionId = $this->executionId();

        $result = $engine->start($executionId, function (WorkflowEnvironment $env) {
            $a1 = $env->activity('a', []);
            $a2 = $env->activity('b', []);
            $a3 = $env->activity('c', []);

            return $env->parallel($a1, $a2, $a3);
        });

        self::assertSame([1, 2, 3], $result);
    }

    #[Test]
    public function anyReturnsFirstResult(): void
    {
        $stack = $this->stack();
        $activityExecutor = $stack['activityExecutor'];
        $activityExecutor->register('slow', fn () => 1);
        $activityExecutor->register('fast', fn () => 2);

        $engine = new ExecutionEngine($stack['eventStore'], $stack['runtime']);
        $executionId = $this->executionId();

        $result = $engine->start($executionId, function (WorkflowEnvironment $env) {
            $a1 = $env->activity('slow', []);
            $a2 = $env->activity('fast', []);

            return $env->any($a1, $a2);
        });

        self::assertContains($result, [1, 2]);
    }

    #[Test]
    public function delayCompletesWhenTimerFires(): void
    {
        $eventStore = new InMemoryEventStore();
        $activityTransport = new InMemoryActivityTransport();
        $activityExecutor = new RegistryActivityExecutor();
        $runtime = new ExecutionRuntime(
            $eventStore,
            $activityTransport,
            $activityExecutor,
            0,
            static fn (): float => \PHP_FLOAT_MAX,
        );

        $engine = new ExecutionEngine($eventStore, $runtime);
        $executionId = $this->executionId();

        $result = $engine->start($executionId, function (WorkflowEnvironment $env) {
            $env->delay(1.0);

            return 'done';
        });

        self::assertSame('done', $result);
        $events = iterator_to_array($eventStore->readStream($executionId));
        self::assertSame(
            [ExecutionStarted::class, TimerScheduled::class, TimerCompleted::class, ExecutionCompleted::class],
            array_map(static fn ($e) => $e::class, $events),
        );
    }

    #[Test]
    public function timerHelperBehavesLikeDelay(): void
    {
        $eventStore = new InMemoryEventStore();
        $activityTransport = new InMemoryActivityTransport();
        $activityExecutor = new RegistryActivityExecutor();
        $runtime = new ExecutionRuntime(
            $eventStore,
            $activityTransport,
            $activityExecutor,
            0,
            static fn (): float => \PHP_FLOAT_MAX,
        );

        $engine = new ExecutionEngine($eventStore, $runtime);
        $executionId = $this->executionId();

        $result = $engine->start($executionId, function (WorkflowEnvironment $env) {
            $env->timer(0.5);

            return 'ok';
        });

        self::assertSame('ok', $result);
        $events = iterator_to_array($eventStore->readStream($executionId));
        self::assertSame(
            [ExecutionStarted::class, TimerScheduled::class, TimerCompleted::class, ExecutionCompleted::class],
            array_map(static fn ($e) => $e::class, $events),
        );
    }
}
