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
 * A signal name is given as a backed enum: a typo is now the type engine's business, no longer
 * that of a wait which never settles. On the wire, it is always the backed value.
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
        // Journalled under the backed value, as any external sender would do.
        $store->append(new WorkflowSignalReceived('signal-enum-1', 'approve', ['by' => 'alice']));

        $result = $engine->resume(
            'signal-enum-1',
            static function (WorkflowEnvironment $wf): array {
                $approvals = [];
                $wf->onSignal(SampleSignal::Approve, static function (array $payload) use (&$approvals): void {
                    $approvals[] = $payload;
                });
                $wf->await(static function () use (&$approvals): bool {
                    return [] !== $approvals;
                });

                return array_shift($approvals);
            },
        );

        self::assertSame(['by' => 'alice'], $result);
    }

    public function testTheMessengerMessageCarriesTheBackedValue(): void
    {
        // The sender types its intent; the message itself is serialized and carries only a string.
        $message = new DeliverWorkflowSignalMessage('exec-1', SampleSignal::Approve, ['by' => 'bob']);

        self::assertSame('approve', $message->signalName);
    }
}
