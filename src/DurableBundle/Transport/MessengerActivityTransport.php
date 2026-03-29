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
 * Adapte ActivityTransportInterface pour utiliser Symfony Messenger.
 * Enqueue = send, Dequeue = get + ack.
 *
 * Si les métadonnées contiennent « retry_delay_seconds », un {@see DelayStamp} est appliqué
 * (comportement proche des retentatives Temporal / file différée).
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
        $meta = $message->metadata;
        $delayMs = 0;
        if (isset($meta['retry_delay_seconds'])) {
            $delayMs = (int) round((float) $meta['retry_delay_seconds'] * 1000);
            unset($meta['retry_delay_seconds']);
        }
        $clean = new ActivityMessage(
            $message->executionId,
            $message->activityId,
            $message->activityName,
            $message->payload,
            $meta,
        );

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
