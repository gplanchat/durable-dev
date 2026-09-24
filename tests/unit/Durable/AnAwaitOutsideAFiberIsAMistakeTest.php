<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Awaitable\Deferred;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use PHPUnit\Framework\TestCase;

/**
 * #329: in distributed mode every workflow runs in a fiber, and an unsettled await outside one
 * used to pass for a suspension. Nothing would ever resume it: it is a programming error.
 */
final class AnAwaitOutsideAFiberIsAMistakeTest extends TestCase
{
    public function testItIsALogicErrorNotASuspension(): void
    {
        $store = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $runtime = new ExecutionRuntime($store, $transport, new RegistryActivityExecutor(), 0, null, true);

        $this->expectException(\LogicException::class);
        $runtime->await((new Deferred())->awaitable(), new ExecutionContext(
            'exec-1',
            new EventStoreHistorySource($store, 'exec-1'),
            new EventStoreCommandBuffer($store, $transport, 'exec-1'),
        ));
    }
}
