<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The profiler's journal rows say what happened to their action, as the Magento and Sylius pages
 * do (#332): without it, the scheduling, the start and the result of one activity read alike.
 */
final class ProfilerRowsSayTheirPhaseTest extends TestCase
{
    public function testEachJournalRowCarriesThePhaseOfItsAction(): void
    {
        $events = new InMemoryEventStore();
        $events->append(new ActivityScheduled('exec-1', 'act-1', 'charge', []));
        $events->append(new ActivityTaskStarted('exec-1', 'act-1', 'charge', 1));
        $events->append(new ActivityTaskFailed('exec-1', 'act-1', 'charge', 1, \RuntimeException::class, 'boom', ActivityRetryState::InProgress));
        $events->append(new ActivityCompleted('exec-1', 'act-1', 'ok'));
        $events->append(new WorkflowSignalReceived('exec-1', 'orderApproved', []));

        $collector = new DurableDataCollector(new DurableExecutionTrace(), new InMemoryWorkflowMetadataStore(), $events);
        $collector->collect(new Request(['durable_execution' => 'exec-1']), new Response());

        self::assertSame(
            ['requested', 'started', 'failed', 'settled', null],
            array_map(static fn(array $row): mixed => \array_key_exists('phase', $row) ? $row['phase'] : 'missing', $collector->getStoreEventRows()),
        );
    }
}
