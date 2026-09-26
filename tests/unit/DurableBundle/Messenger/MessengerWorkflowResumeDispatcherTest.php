<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Bundle\Messenger;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Bundle\Messenger\MessengerWorkflowResumeDispatcher;
use Gplanchat\Durable\Bundle\Messenger\NewWorkflowRunStamp;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsWorkflow(name: 'test.order-fulfilment')]
final class OrderFulfilmentWorkflow
{
    #[AsWorkflowMethod]
    public function run(): void {}
}

final class MessengerWorkflowResumeDispatcherTest extends TestCase
{
    /** #258: a caller passing `::class` gets the alias in the metadata the diagnose command and the profiler show. */
    public function testANewRunStartedByClassIsRecordedUnderItsAlias(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->sent[] = Envelope::wrap($message, $stamps);
            }
        };
        $metadata = new InMemoryWorkflowMetadataStore();

        (new MessengerWorkflowResumeDispatcher($bus, $metadata))
            ->dispatchNewWorkflowRun('order-1', OrderFulfilmentWorkflow::class, []);

        self::assertSame('test.order-fulfilment', $metadata->get('order-1')['workflowType'] ?? null);
        self::assertSame('test.order-fulfilment', $bus->sent[0]->last(NewWorkflowRunStamp::class)?->workflowType);
    }
}
