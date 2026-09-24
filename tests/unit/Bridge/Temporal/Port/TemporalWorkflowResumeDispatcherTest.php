<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Port;

use Gplanchat\Bridge\Temporal\Port\TemporalWorkflowResumeDispatcher;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Durable\Debug\WorkflowDispatchObserverInterface;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The bridge reports its dispatches to a core port, so a host without the Symfony bundle (Laravel,
 * Magento) can observe them too, and the bridge never loads a bundle class (#345).
 */
#[CoversClass(TemporalWorkflowResumeDispatcher::class)]
final class TemporalWorkflowResumeDispatcherTest extends TestCase
{
    public function testAnyCoreDispatchObserverHearsOfANewRun(): void
    {
        $observer = new class implements WorkflowDispatchObserverInterface {
            /** @var list<array{string, string, bool, ?string}> */
            public array $heard = [];

            public function onWorkflowDispatchRequested(string $executionId, string $workflowType, array $payload, bool $isResume, ?string $transportNames): void
            {
                $this->heard[] = [$executionId, $workflowType, $isResume, $transportNames];
            }
        };

        $dispatcher = new TemporalWorkflowResumeDispatcher(
            $this->createStub(WorkflowClientInterface::class),
            new InMemoryWorkflowMetadataStore(),
            new WorkflowDefinitionLoader(),
            $observer,
        );
        $dispatcher->dispatchNewWorkflowRun('exec-1', 'Order', ['id' => 7]);

        self::assertSame([['exec-1', 'Order', false, 'temporal']], $observer->heard);
    }
}
