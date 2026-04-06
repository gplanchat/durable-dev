<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Durable\WorkflowRegistry;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Receive-only transport: each {@see get()} long-polls Temporal and completes workflow tasks.
 * Consumed with {@code messenger:consume <transport_name>} (no serialized application message; no handler).
 *
 * Replaced JournalWorkflowTaskProcessor with WorkflowTaskProcessor (native fiber-based execution path).
 */
final class TemporalJournalTransport implements TransportInterface
{
    public function __construct(
        private readonly WorkflowTaskProcessor $processor,
    ) {
    }

    public function get(): iterable
    {
        $this->processor->processOne();

        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        throw new LogicException('temporal transport (purpose=journal) is receive-only.');
    }

    public static function fromConnection(TemporalConnection $connection, WorkflowRegistry $registry): self
    {
        $client = WorkflowServiceClientFactory::create($connection);
        $cursor = new TemporalHistoryCursor($client, $connection->namespace);
        $runner = new WorkflowTaskRunner($cursor, $registry, $connection);
        $processor = new WorkflowTaskProcessor($client, $connection, $runner);

        return new self($processor);
    }
}
