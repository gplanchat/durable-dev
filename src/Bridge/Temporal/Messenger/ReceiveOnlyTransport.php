<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;

/**
 * For a Temporal receiver that does its work inside get() and hands Messenger nothing: there is
 * nothing to send to it, and nothing to acknowledge or reject. Messenger's per-message machinery
 * (retry, failure transport, --limit, --failure-limit) therefore never engages on it (#353).
 */
trait ReceiveOnlyTransport
{
    /** What it is called in the message that refuses a send, e.g. "temporal activity worker". */
    abstract protected function receiveOnlyName(): string;

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}

    public function send(Envelope $envelope): Envelope
    {
        throw new LogicException(\sprintf('%s transport is receive-only.', $this->receiveOnlyName()));
    }
}
