<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Workflow\Saga;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * Every activity suspends here, so the saga is rebuilt on each resume: a compensation that already
 * ran must replay from the journal, not run again.
 */
final class SagaTest extends TestCase
{
    private InMemoryEventStore $eventStore;
    private InMemoryActivityTransport $transport;
    private RegistryActivityExecutor $executor;
    private ExecutionRuntime $runtime;
    private ExecutionEngine $engine;

    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $this->transport = new InMemoryActivityTransport();
        $this->executor = new RegistryActivityExecutor();
        $this->runtime = new ExecutionRuntime($this->eventStore, $this->transport, $this->executor, 0, null, true);
        $this->engine = new ExecutionEngine($this->eventStore, $this->runtime);
        $this->executor->register('append', function (array $payload): string {
            $this->ran[] = $payload['text'];

            return $payload['text'];
        });
    }

    public function testCompensationsRunInReverseOrderExactlyOnce(): void
    {
        $handler = static function (WorkflowEnvironment $env): mixed {
            $steps = $env->activityStub(SuiteActivities::class);
            $saga = new Saga();

            try {
                $env->await($steps->append('reserve'));
                $saga->addCompensation(static fn() => $env->await($steps->append('release')));

                $env->await($steps->append('charge'));
                $saga->addCompensation(static fn() => $env->await($steps->append('refund')));

                throw new \RuntimeException('shipping failed');
            } catch (\RuntimeException $e) {
                $saga->compensate();

                throw $e;
            }
        };

        $this->expectExceptionMessage('shipping failed');

        try {
            $this->driveToTheEnd('saga-1', $handler);
        } finally {
            self::assertSame(['reserve', 'charge', 'refund', 'release'], $this->ran);
        }
    }

    public function testAFailingCompensationStopsTheOnesBeforeIt(): void
    {
        $handler = static function (WorkflowEnvironment $env): mixed {
            $steps = $env->activityStub(SuiteActivities::class);
            $saga = new Saga();

            $saga->addCompensation(static fn() => $env->await($steps->append('never compensated')));
            $saga->addCompensation(static fn() => throw new \LogicException('refund refused'));
            $saga->addCompensation(static fn() => $env->await($steps->append('compensated')));

            $saga->compensate();

            return 'unreachable';
        };

        $this->expectExceptionMessage('refund refused');

        try {
            $this->driveToTheEnd('saga-2', $handler);
        } finally {
            self::assertSame(['compensated'], $this->ran);
        }
    }

    public function testACompensationRunsOnceAcrossTwoCalls(): void
    {
        $handler = static function (WorkflowEnvironment $env): int {
            $calls = 0;
            $saga = new Saga();
            $saga->addCompensation(static function () use (&$calls): void {
                ++$calls;
            });

            $saga->compensate();
            $saga->compensate();

            return $calls;
        };

        self::assertSame(1, $this->driveToTheEnd('saga-3', $handler));
    }

    public function testACompensationThatForgetsToAwaitIsRefused(): void
    {
        $handler = static function (WorkflowEnvironment $env): mixed {
            $saga = new Saga();
            $saga->addCompensation(static fn() => $env->activityStub(SuiteActivities::class)->append('refund'));

            $saga->compensate();

            return 'unreachable';
        };

        $this->expectException(\LogicException::class);

        try {
            $this->driveToTheEnd('saga-4', $handler);
        } finally {
            self::assertSame([], $this->ran);
        }
    }

    private function driveToTheEnd(string $executionId, callable $handler): mixed
    {
        try {
            return $this->engine->start($executionId, $handler);
        } catch (WorkflowSuspendedException) {
        }

        for ($pass = 0; $pass < 10; ++$pass) {
            $this->runtime->runUntilIdle(new ExecutionContext(
                $executionId,
                new EventStoreHistorySource($this->eventStore, $executionId),
                new EventStoreCommandBuffer($this->eventStore, $this->transport, $executionId),
            ));

            try {
                return $this->engine->resume($executionId, $handler);
            } catch (WorkflowSuspendedException) {
            }
        }

        self::fail('the execution did not settle in ten passes');
    }
}
