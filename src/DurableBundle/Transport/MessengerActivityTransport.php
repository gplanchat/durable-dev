<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Transport;

use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Adapts ActivityTransportInterface to use Symfony Messenger.
 * Enqueue = send, Dequeue = get + ack.
 *
 * If the metadata contains "retry_delay_seconds", a {@see DelayStamp} is applied
 * (behaviour close to Temporal retries / a delayed queue).
 */
final class MessengerActivityTransport implements ActivityTransportInterface
{
    private ?Envelope $pending = null;

    public function __construct(
        private readonly SenderInterface $sender,
        private readonly ReceiverInterface $receiver,
    ) {}

    public function enqueue(ActivityMessage $message): void
    {
        // Same principle: the deferral becomes a DelayStamp, and disappears from the message.
        $delayMs = null !== $message->retryDelay ? (int) round($message->retryDelay->toSeconds() * 1000.0) : 0;
        $clean = $message->withoutRetryDelay();

        $stamps = [];
        if ($delayMs > 0) {
            $stamps[] = new DelayStamp($delayMs);
        }
        $this->sender->send(Envelope::wrap($clean, $stamps));
    }

    public function dequeue(): ?ActivityMessage
    {
        if (null !== $this->pending) {
            $envelope = $this->pending;
            $this->pending = null;
            $message = $envelope->getMessage();
            if ($message instanceof ActivityMessage) {
                $this->receiver->ack($envelope);

                return $message;
            }
            $this->receiver->reject($envelope);

            return null;
        }

        foreach ($this->receiver->get() as $envelope) {
            $message = $envelope->getMessage();
            if (!$message instanceof ActivityMessage) {
                $this->receiver->reject($envelope);

                continue;
            }

            $this->receiver->ack($envelope);

            return $message;
        }

        return null;
    }

    /**
     * Messenger carries the deferral itself (DelayStamp): the worker does not have to wait for
     * a deadline on the PHP side.
     */
    public function nextDueAt(): ?float
    {
        return $this->isEmpty() ? null : microtime(true);
    }

    public function isEmpty(): bool
    {
        if (null !== $this->pending) {
            return false;
        }

        foreach ($this->receiver->get() as $envelope) {
            if ($envelope->getMessage() instanceof ActivityMessage) {
                $this->pending = $envelope;

                return false;
            }
            $this->receiver->reject($envelope);
        }

        return true;
    }

    public function removePendingFor(string $executionId, string $activityId): bool
    {
        return false;
    }
}
