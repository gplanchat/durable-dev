<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\Journal\HistoryPageMerger;
use Gplanchat\Bridge\Temporal\Journal\JournalWorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\TemporalJournalGrpcPoller;
use Gplanchat\Bridge\Temporal\TemporalJournalSettings;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Receive-only transport: each consumer tick long-polls Temporal and completes journal workflow tasks.
 * No messages are dispatched to handlers; processing is synchronous inside {@see get()}.
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
        throw new LogicException('temporal-journal transport is receive-only.');
    }

    public static function fromSettings(TemporalJournalSettings $settings): self
    {
        $client = WorkflowServiceClientFactory::create($settings);
        $merger = new HistoryPageMerger($client, $settings->namespace);
        $processor = new JournalWorkflowTaskProcessor($client, $settings, $merger);
        $poller = new TemporalJournalGrpcPoller($client, $settings);

        return new self($poller, $processor);
    }
}
