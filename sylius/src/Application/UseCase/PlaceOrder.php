<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\Port\Payments;
use App\Domain\Payment\Money;
use App\Domain\Payment\OrderId;
use App\Domain\Payment\OrderOutcome;

/**
 * Have an order billed: ask first, commit second.
 *
 * The order of these two calls is the compensation. Neither `verify` nor `charge` has an operation
 * that gives back what it took, so the only protection is asking everything that can say no before
 * anything moves. Two inversions of this rule were written first and measured, and both cost real
 * money in the demonstration.
 *
 * ⚠ **This use case runs inside a replayed workflow.** Its port's adapter waits through
 * `WorkflowEnvironment::await()`, so `__invoke()` is resumed from the top on every replay and may
 * only be called from workflow context. A controller cannot call it. That is a deliberate choice
 * over the alternative, which is a port returning an awaitable and an `await()` left in the
 * workflow: it costs this warning and buys a use case that reads like any other.
 */
final readonly class PlaceOrder
{
    public function __construct(
        private Payments $payments,
    ) {}

    public function __invoke(OrderId $order, Money $amount): OrderOutcome
    {
        $authorisation = $this->payments->authorise($order, $amount);

        if (!$authorisation->isGranted()) {
            // Refused: nothing to charge, and nothing to compensate either.
            return OrderOutcome::refused($authorisation);
        }

        return OrderOutcome::paid($authorisation, $this->payments->capture($order, $amount));
    }
}
