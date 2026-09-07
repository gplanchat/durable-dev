<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingContract;
use Gplanchat\Durable\Demo\Contracts\Delivery\DeliveryContract;
use Gplanchat\Durable\Demo\Contracts\Stock\StockContract;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Magento places an order of which **nothing is served by Magento**.
 *
 * The stock is held by the Sylius shop, the invoice is verified then charged by the Symfony
 * business, and the shipment is scheduled then made by the Laravel logistics. Four applications,
 * four namespaces, three frameworks, and this one has no Nexus handler, no Nexus task queue and no
 * compiler pass registering anything, because **calling needs none of them**.
 * `WorkflowEnvironment::nexusStub()` reads the contract by reflection, and the worker that advances
 * this execution is the same `WorkflowTaskRunner` the two other mockups run under another name.
 *
 * Both answer shapes are here, as in the shop's `OrderWorkflow`: `verify` and `reserve` come back on
 * the task, `charge` is fulfilled by a workflow on the other side and takes some fifteen seconds.
 * Nothing in this file says which is which.
 *
 * ⚠ **The naming guard does not cover this host.** The rule that every parameter of a workflow
 * fulfilling an operation must be a parameter of the contract lives in `NexusHandlerPass`, and so in
 * Symfony's container. It guards the **server**, and Magento serves nothing: what matters here is
 * that the names passed to the stub's methods are the contract's, which the contract's typed
 * signature already checks.
 */
final class OrderNexusWorkflow
{
    /** The shop's endpoint, created by `bin/demo-nexus`. */
    public const ENDPOINT_STOCK = 'demo-shop-stock';

    /** The business's. */
    public const ENDPOINT_BILLING = 'demo-business-billing';

    /** The logistics'. */
    public const ENDPOINT_DELIVERY = 'demo-laravel-delivery';

    /** @var NexusStub<StockContract> */
    private readonly NexusStub $stock;

    /** @var NexusStub<BillingContract> */
    private readonly NexusStub $billing;

    /** @var NexusStub<DeliveryContract> */
    private readonly NexusStub $delivery;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->stock = $environment->nexusStub(StockContract::class, endpoint: self::ENDPOINT_STOCK);
        $this->billing = $environment->nexusStub(BillingContract::class, endpoint: self::ENDPOINT_BILLING);
        $this->delivery = $environment->nexusStub(DeliveryContract::class, endpoint: self::ENDPOINT_DELIVERY);
    }

    /**
     * @param array<string, int> $lines  reference => quantity
     * @param int                $amount in cents
     *
     * @return array{
     *     verified: array{accepted: bool, reason: string|null},
     *     reservation: array{reserved: bool, missing: array<string, int>}|null,
     *     charge: array{receipt: string, charged: int}|null,
     *     delivery: array{scheduled: bool, slot: string, carrier: string, reason: string|null}|null,
     *     shipment: array{shipped: bool, tracking: string}|null
     * }
     */
    #[AsWorkflowMethod]
    public function run(string $order, array $lines, int $amount, string $currency = 'EUR'): array
    {
        // ⚠ **The order of the five calls is what spares any compensation**, and both inversions
        // were measured before they were fixed:
        //
        // - holding the stock before verifying the invoice left `MUG_BLUE` held at the shop after a
        //   currency refusal;
        // - charging before scheduling the round made an order paid for that logistics then refused
        //   to carry.
        //
        // None of the three contracts has an operation that gives back what it took. **Ask first
        // everything that can say no, commit only afterwards**: the order is the compensation, for
        // want of having one.
        $verdict = $this->environment->await($this->billing->verify($order, $amount, $currency));

        if (true !== ($verdict['accepted'] ?? false)) {
            return self::nothing($verdict);
        }

        $delivery = $this->environment->await($this->delivery->schedule($order, $lines));

        if (true !== ($delivery['scheduled'] ?? false)) {
            return array_merge(self::nothing($verdict), ['delivery' => $delivery]);
        }

        $reservation = $this->environment->await($this->stock->reserve($order, $lines));

        if (true !== ($reservation['reserved'] ?? false)) {
            return array_merge(self::nothing($verdict), [
                'delivery' => $delivery,
                'reservation' => $reservation,
            ]);
        }

        // The two commitments, once the three possible refusals have been ruled out. `charge` is
        // fulfilled by a workflow of the business, `ship` by a workflow of the logistics, which in
        // turn calls the shop back while it serves. Three hosts, three frameworks, and the same
        // `await` for all five calls.
        return [
            'verified' => $verdict,
            'reservation' => $reservation,
            'charge' => $this->environment->await($this->billing->charge($order, $amount, $currency)),
            'delivery' => $delivery,
            'shipment' => $this->environment->await(
                $this->delivery->ship($order, $delivery['slot']),
            ),
        ];
    }

    /**
     * The result of an order that stops before it has cost anybody anything.
     *
     * @param array{accepted: bool, reason: string|null} $verdict
     *
     * @return array{verified: array{accepted: bool, reason: string|null}, reservation: null, charge: null, delivery: null, shipment: null}
     */
    private static function nothing(array $verdict): array
    {
        return [
            'verified' => $verdict,
            'reservation' => null,
            'charge' => null,
            'delivery' => null,
            'shipment' => null,
        ];
    }
}
