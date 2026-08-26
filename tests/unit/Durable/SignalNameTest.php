<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

enum SampleSignal: string
{
    case Approve = 'approve';
}

/**
 * Le nom d'un signal se donne en enum adossée : une faute de frappe relève du moteur de types,
 * plus d'une attente qui ne se règle jamais. Sur le fil, c'est toujours la valeur adossée.
 */
final class SignalNameTest extends TestCase
{
    public function testAnEnumNamesTheSameSignalAsItsBackedValue(): void
    {
        $store = new InMemoryEventStore();
        $engine = new ExecutionEngine(
            $store,
            new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
        );

        $store->append(new ExecutionStarted('signal-enum-1', []));
        // Journalisé sous la valeur adossée, comme le ferait n'importe quel émetteur externe.
        $store->append(new WorkflowSignalReceived('signal-enum-1', 'approve', ['by' => 'alice']));

        $result = $engine->resume(
            'signal-enum-1',
            static fn(WorkflowEnvironment $wf): array => $wf->waitSignal(SampleSignal::Approve),
        );

        self::assertSame(['by' => 'alice'], $result);
    }

    public function testTheMessengerMessageCarriesTheBackedValue(): void
    {
        // L'émetteur type son intention ; le message, lui, est sérialisé et ne transporte qu'une chaîne.
        $message = new DeliverWorkflowSignalMessage('exec-1', SampleSignal::Approve, ['by' => 'bob']);

        self::assertSame('approve', $message->signalName);
    }
}
