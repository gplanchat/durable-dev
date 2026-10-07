<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use PHPUnit\Framework\TestCase;

/**
 * No journal backend reads an activity's heartbeat timeout (#977): the command buffer they share
 * refuses it by name. Temporal sends it to the server and keeps accepting it.
 */
final class AnActivityHeartbeatTimeoutIsRefusedOnAJournalTest extends TestCase
{
    private function options(): ActivityOptions
    {
        return (new ActivityOptions())->withTimeouts(new ActivityTimeouts(heartbeat: Duration::seconds(5)));
    }

    public function testTheJournalCommandBufferRefusesIt(): void
    {
        $store = new InMemoryEventStore();
        $buffer = new EventStoreCommandBuffer($store, new NoopActivityTransport(), ExecutionId::fromString('exec-1'));

        try {
            $buffer->scheduleActivity('a-1', 'charge', [], $this->options());
            self::fail('The heartbeat timeout was accepted.');
        } catch (UnsupportedByBackendException $e) {
            self::assertStringContainsString('ActivityTimeouts::$heartbeat', $e->getMessage());
            self::assertStringContainsString('journal', $e->getMessage());
            self::assertStringContainsString('Temporal', $e->getMessage());
        }
        self::assertCount(0, iterator_to_array($store->readStream(ExecutionId::fromString('exec-1'))), 'Nothing is journaled for a refused call.');
    }

    public function testAnActivityWithoutItIsAccepted(): void
    {
        $store = new InMemoryEventStore();
        $buffer = new EventStoreCommandBuffer($store, new NoopActivityTransport(), ExecutionId::fromString('exec-1'));

        $buffer->scheduleActivity('a-1', 'charge', [], new ActivityOptions());

        self::assertCount(1, iterator_to_array($store->readStream(ExecutionId::fromString('exec-1'))));
    }

    public function testTheTemporalBufferStillSendsIt(): void
    {
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), ExecutionId::fromString('exec-1'));
        $buffer->scheduleActivity('a-1', 'charge', [], $this->options());

        $attrs = $buffer->peek()[0]->getScheduleActivityTaskCommandAttributes();
        self::assertSame(5, $attrs?->getHeartbeatTimeout()?->getSeconds());
    }
}
