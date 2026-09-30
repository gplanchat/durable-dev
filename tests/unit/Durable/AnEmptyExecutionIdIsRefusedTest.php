<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\PassEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\WorkflowFiberDriver;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * M8 (#329): an empty identifier names no execution, and every store would file it under "".
 *
 * Two helpers keep a string id until #682's last part, and convert it on entry: an empty id is
 * refused there too, whatever the store behind (UPGRADE).
 */
final class AnEmptyExecutionIdIsRefusedTest extends TestCase
{
    public function testAnEmptyStringIsNotAnExecutionId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ExecutionId::fromString('');
    }

    public function testAnyOtherStringIs(): void
    {
        self::assertSame('exec-1', ExecutionId::fromString('exec-1')->toString());
    }

    public function testAPassOverAFencedStoreRefusesAnEmptyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PassEventStore::open(new InMemoryEventStore(), '');
    }

    public function testAPassOverAStoreThatCannotFenceRefusesAnEmptyIdToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PassEventStore::open($this->createStub(EventStoreInterface::class), '');
    }

    public function testTheFiberDriverRefusesAnEmptyIdBeforeTheLifecycleHearsOfIt(): void
    {
        $store = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $runtime = new ExecutionRuntime($store, $transport, new RegistryActivityExecutor(), 0, null, true);
        $context = new ExecutionContext(
            'exec-1',
            new EventStoreHistorySource($store, 'exec-1'),
            new EventStoreCommandBuffer($store, $transport, 'exec-1'),
        );
        $lifecycle = $this->createMock(WorkflowLifecycleInterface::class);
        $lifecycle->expects(self::never())->method('onBeforeRun');

        $this->expectException(\InvalidArgumentException::class);

        (new WorkflowFiberDriver($lifecycle))->run('', $context, new WorkflowEnvironment($context, $runtime), static fn(): null => null);
    }
}
