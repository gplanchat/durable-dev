<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Enveloppe d'un awaitable issu de {@see \Gplanchat\Durable\ExecutionContext::delay()} / {@see \Gplanchat\Durable\ExecutionContext::timer()}.
 *
 * Permet au runtime distribué de distinguer une attente timer (reprise par worker / horloge) d'une attente signal/update.
 */
final class TimerAwaitable implements Awaitable
{
    public function __construct(
        private readonly Awaitable $inner,
        private readonly string $timerId,
    ) {
    }

    public function timerId(): string
    {
        return $this->timerId;
    }

    public function inner(): Awaitable
    {
        return $this->inner;
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
    {
        $this->inner->then($onFulfilled, $onRejected);
    }

    public function isSettled(): bool
    {
        return $this->inner->isSettled();
    }

    public function getResult(): mixed
    {
        return $this->inner->getResult();
    }
}
