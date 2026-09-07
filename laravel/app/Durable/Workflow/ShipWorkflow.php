<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Demo\Contracts\Delivery\DeliveryContract;
use Gplanchat\Durable\Demo\Contracts\Stock\StockContract;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * What fulfils `delivery/ship`, and what in turn calls a third application.
 *
 * Two things fit in this class, and the second is the one no other mockup shows:
 *
 * 1. **It fulfils an operation.** There is no handler method for `ship`: the plumbing starts this
 *    workflow with the task's callback attached, and the server delivers its result to the caller
 *    when it finishes. Six seconds of warehouse preparation are enough to show it — past the budget
 *    of a Nexus task, short of a reader's patience.
 * 2. **It calls while it serves.** Before the goods leave, logistics asks the shop for its verdict
 *    again, through `stock/reserve`, on an endpoint that is not its own. The call is safe because
 *    `reserve` is idempotent per order identifier: the shop **reads back** the decision taken at
 *    order time instead of taking a new one — which is why the lines passed here are empty.
 *
 * The resulting execution therefore carries one Nexus operation **served** and one Nexus operation
 * **called**, in the same journal, on the same host, and that host is Laravel.
 *
 * ⚠ **The parameter names are the interface.** `order` and `slot` are the ones
 * `DeliveryContract::ship()` declares, and the payload is keyed by name on both sides. Renaming one
 * here without renaming it there makes the registration refuse, naming both signatures:
 * `NexusFulfilmentParameterNames` is called by `DeclaredNexusOperations` as it is by Symfony's
 * compiler pass.
 */
#[AsWorkflow(self::TYPE)]
#[FulfilsNexusOperation(DeliveryContract::class, 'ship')]
final class ShipWorkflow
{
    public const TYPE = 'ShipWorkflow';

    /** The shop's endpoint, the very one the other callers use. */
    public const ENDPOINT_STOCK = 'demo-shop-stock';

    /** @var NexusStub<StockContract> */
    private readonly NexusStub $stock;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->stock = $environment->nexusStub(StockContract::class, endpoint: self::ENDPOINT_STOCK);
    }

    /**
     * @return array{shipped: bool, tracking: string}
     */
    #[AsWorkflowMethod]
    public function run(string $order, string $slot): array
    {
        // The warehouse preparation. `sleep()` waits; `timer()` returns an awaitable that has to be
        // awaited — confusing the two has already produced a `TimerStarted` with no `TimerFired` in
        // this repository.
        $this->environment->sleep(6.0, 'warehouse preparation');

        // Empty lines: this is not asking for a new reservation, it reads back the one the order
        // identifier already decided at the shop.
        $verdict = $this->environment->await($this->stock->reserve($order, []));

        if (true !== ($verdict['reserved'] ?? false)) {
            // The stock is no longer held: nothing leaves, and the caller learns it from the
            // operation's result rather than from an exception — it is a business outcome, not a
            // breakdown.
            return ['shipped' => false, 'tracking' => ''];
        }

        return [
            'shipped' => true,
            'tracking' => 'TRK-' . strtoupper(substr(md5($order . $slot), 0, 10)),
        ];
    }
}
