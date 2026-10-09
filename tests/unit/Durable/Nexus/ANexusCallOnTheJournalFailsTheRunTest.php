<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * #851: a workflow that calls a Nexus operation on the journal backend ends failed, and the journal
 * says why (DUR036). The refusal also leaves the engine, so the transport sees a failed message.
 */
final class ANexusCallOnTheJournalFailsTheRunTest extends TestCase
{
    public function testTheRefusalIsJournalledAsTheRunsFailure(): void
    {
        $store = new InMemoryEventStore();
        $engine = new ExecutionEngine(
            $store,
            new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
        );

        try {
            $engine->start(ExecutionId::fromString('nexus-1'), static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->nexusOperation('billing-endpoint', 'billing', 'charge')));
            self::fail('the run was to fail');
        } catch (NexusUnsupportedByBackendException) {
        }

        $failures = array_values(array_filter(
            iterator_to_array($store->readStream(ExecutionId::fromString('nexus-1')), false),
            static fn(object $event): bool => $event instanceof WorkflowExecutionFailed,
        ));
        self::assertCount(1, $failures);
        self::assertSame(NexusUnsupportedByBackendException::class, $failures[0]->failureClass());
    }
}
