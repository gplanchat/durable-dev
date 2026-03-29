<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Maquette;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\WorkflowEnvironment;
use integration\Gplanchat\Durable\Support\DurableTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
#[CoversClass(ExecutionEngine::class)]
final class ScheduleAndCompleteTest extends DurableTestCase
{
    #[Test]
    public function scheduleActivityDrainAndComplete(): void
    {
        $stack = $this->stack();
        $eventStore = $stack['eventStore'];
        $activityExecutor = $stack['activityExecutor'];
        $runtime = $stack['runtime'];
        $executionId = $this->executionId();

        $activityExecutor->register('echo', fn (array $p) => $p['message'] ?? 'default');

        $engine = new ExecutionEngine($eventStore, $runtime);

        $result = $engine->start($executionId, function (WorkflowEnvironment $env) {
            return $env->await($env->activity('echo', ['message' => 'hello']));
        });

        self::assertSame('hello', $result);
        $this->assertEventTypesOrder(
            $executionId,
            ExecutionStarted::class,
            ActivityScheduled::class,
            ActivityCompleted::class,
            ExecutionCompleted::class,
        );
    }

    #[Test]
    public function multipleActivities(): void
    {
        $stack = $this->stack();
        $eventStore = $stack['eventStore'];
        $activityExecutor = $stack['activityExecutor'];
        $runtime = $stack['runtime'];
        $executionId = $this->executionId();

        $activityExecutor->register('add', fn (array $p) => ($p['a'] ?? 0) + ($p['b'] ?? 0));

        $engine = new ExecutionEngine($eventStore, $runtime);

        $result = $engine->start($executionId, function (WorkflowEnvironment $env) {
            $a = $env->await($env->activity('add', ['a' => 1, 'b' => 2]));

            return $env->await($env->activity('add', ['a' => $a, 'b' => 3]));
        });

        self::assertSame(6, $result);
        $this->assertEventTypesOrder(
            $executionId,
            ExecutionStarted::class,
            ActivityScheduled::class,
            ActivityCompleted::class,
            ActivityScheduled::class,
            ActivityCompleted::class,
            ExecutionCompleted::class,
        );
    }
}
