<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Integration\Maquette;

use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\Tests\Support\DurableTestCase;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
#[CoversClass(ExecutionEngine::class)]
final class ReplayFromHistoryTest extends DurableTestCase
{
    #[Test]
    public function replayResolvesFromHistoryWithoutReExecutingActivity(): void
    {
        $stack = $this->stack();
        $eventStore = $stack['eventStore'];
        $activityExecutor = $stack['activityExecutor'];
        $runtime = $stack['runtime'];
        $executionId = $this->executionId();

        $callCount = 0;
        $activityExecutor->register('count', function (array $p) use (&$callCount) {
            ++$callCount;

            return $callCount;
        });

        $engine = new ExecutionEngine($eventStore, $runtime);

        $result = $engine->start($executionId, function (WorkflowEnvironment $env) {
            return $env->await($env->activity('count', []));
        });

        self::assertSame(1, $result);
        self::assertSame(1, $callCount);

        $events = iterator_to_array($eventStore->readStream($executionId));
        self::assertCount(4, $events);

        $newContext = new ExecutionContext($executionId, $eventStore, $runtime->getActivityTransport(), null);
        $replayResult = $newContext->activity('count', []);
        self::assertTrue($replayResult->isSettled());
        self::assertSame(1, $replayResult->getResult());
        self::assertSame(1, $callCount, 'Activity should not be re-executed on replay');
    }
}
