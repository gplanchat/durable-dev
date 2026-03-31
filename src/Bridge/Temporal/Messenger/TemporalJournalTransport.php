<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\Journal\HistoryPageMerger;
use Gplanchat\Bridge\Temporal\Journal\JournalWorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\TemporalJournalGrpcPoller;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Receive-only transport: each {@see get()} long-polls Temporal and completes journal workflow tasks.
 * Consommé avec {@code messenger:consume <nom_du_transport>} (pas de message applicatif sérialisé ; pas de handler).
 */
final class TemporalJournalTransport implements TransportInterface
{
    public function __construct(
        private readonly TemporalJournalGrpcPoller $poller,
        private readonly JournalWorkflowTaskProcessor $processor,
    ) {
    }

    public function get(): iterable
    {
        $poll = $this->poller->pollOnce();
        if ('' !== $poll->getTaskToken()) {
            $this->processor->process($poll);
        }

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

    public static function fromConnection(TemporalConnection $connection): self
    {
        $client = WorkflowServiceClientFactory::create($connection);
        $merger = new HistoryPageMerger($client, $connection->namespace);
        $processor = new JournalWorkflowTaskProcessor($client, $connection, $merger);
        $poller = new TemporalJournalGrpcPoller($client, $connection);

        return new self($poller, $processor);
    }
}
