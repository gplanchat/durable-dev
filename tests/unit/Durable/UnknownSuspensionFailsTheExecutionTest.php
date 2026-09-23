<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * What the journal says when workflow code suspends its fiber with something the engine cannot
 * wait on. Before #315 the answer was: nothing — no event, `null` returned, and the resume handler
 * marked the run completed on that `null`. An execution that stopped for a reason nobody can name
 * is a failure, written down as one.
 */
final class UnknownSuspensionFailsTheExecutionTest extends TestCase
{
    public function testTheExecutionFailsAndTheJournalSaysSo(): void
    {
        $store = new InMemoryEventStore();
        $engine = new ExecutionEngine(
            $store,
            new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
        );

        try {
            $engine->start('unknown-1', static function (WorkflowEnvironment $wf): string {
                \Fiber::suspend(['not' => 'an awaitable']);

                return 'unreachable';
            });
            self::fail('the execution was to fail');
        } catch (\LogicException $e) {
            self::assertStringContainsString('array', $e->getMessage());
        }

        $classes = array_map(static fn(object $e): string => $e::class, iterator_to_array($store->readStream('unknown-1'), false));
        self::assertContains(WorkflowExecutionFailed::class, $classes);
        self::assertNotContains(ExecutionCompleted::class, $classes);
    }
}
