<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\MagentoBench\Fixture\StartsOrderThroughARepository;
use Gplanchat\DurableProbe\Repository\SlowOrderRepository;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

/**
 * #973, #976: the ObjectManager builds a declared repository, and an observer that takes it starts
 * the workflow on the database backend, from the module's own `di.xml` preferences.
 */
final class TheClientPortIsInjectedInADeclaredRepositoryTest extends TestCase
{
    public function testAnObserverStartsAWorkflowThroughADeclaredRepository(): void
    {
        $journal = JournalHarness::adapter();
        $observer = BenchRuntime::objectManager()->create(StartsOrderThroughARepository::class);

        $observer->execute(new Observer(['event' => new Event(['increment' => '000000042'])]));

        self::assertSame(1, (int) $journal->fetchOne('SELECT COUNT(*) FROM durable_workflow_metadata WHERE execution_id = ?', ['order-000000042']), 'the start inserted the metadata row');
        $this->expectException(WorkflowExecutionAlreadyStarted::class);
        BenchRuntime::objectManager()->get(SlowOrderRepository::class)->create(ExecutionId::fromString('order-000000042'))->start(orderId: '000000042');
    }
}
