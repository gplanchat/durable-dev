<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Receive-only transport: each {@see get()} long-polls Temporal and completes workflow tasks.
 * Consumed with {@code messenger:consume durable_workflows} (no serialized application message; no handler).
 */
final class TemporalJournalTransport implements TransportInterface
{
    use ReceiveOnlyTransport;

    public function __construct(
        private readonly WorkflowTaskProcessor $processor,
    ) {}

    public function get(): iterable
    {
        $this->processor->processOne();

        return [];
    }

    protected function receiveOnlyName(): string
    {
        return 'temporal workflow worker';
    }
}
