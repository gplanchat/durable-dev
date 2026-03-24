<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Adapte ActivityTransportInterface pour utiliser Symfony Messenger.
 * Enqueue = send, Dequeue = get + ack.
 */
final class MessengerActivityTransport implements ActivityTransportInterface
{
    private ?Envelope $pending = null;

    public function __construct(
        private readonly SenderInterface $sender,
        private readonly ReceiverInterface $receiver,
    ) {
    }

    public function enqueue(ActivityMessage $message): void
    {
        $this->sender->send(Envelope::wrap($message));
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
