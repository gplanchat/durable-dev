<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Payment\Authorisation;
use App\Domain\Payment\Money;
use App\Domain\Payment\OrderId;
use App\Domain\Payment\Receipt;

/**
 * What the shop needs from whoever bills, in the shop's own words.
 *
 * The billing context is reached over Nexus today. Nothing in this interface says so: no contract,
 * no endpoint, no array. That is the point of it, and the reason
 * {@see \App\Application\UseCase\PlaceOrder} can be exercised without a cluster.
 *
 * ⚠ **Its implementations run inside a replayed workflow.** An adapter that waits does so through
 * `WorkflowEnvironment::await()`, which is only meaningful in workflow context. A controller cannot
 * call this port; see the note on {@see \App\Application\UseCase\PlaceOrder}.
 */
interface Payments
{
    /**
     * Ask whether this order may be charged. Answering is cheap and the other side answers now.
     */
    public function authorise(OrderId $order, Money $amount): Authorisation;

    /**
     * Take the money. The other side fulfils this with a workflow, and it takes as long as it takes.
     */
    public function capture(OrderId $order, Money $amount): Receipt;
}
