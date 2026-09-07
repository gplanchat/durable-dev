<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Demo\Contracts\Stock\StockContract;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * The business asks the shop to hold stock, and waits for its verdict.
 *
 * It is the simpler of the two directions: the operation is served **right away**, by a method the
 * shop wrote. Nothing here says so and nothing here knows it — this workflow awaits an operation,
 * and the result arrives when it arrives. If the shop decided tomorrow to fulfil `reserve` with a
 * workflow of its own, this class would not change by a line.
 *
 * The contract read is `StockContract`, the caller's, and not `StockServed`, the one the handler
 * implements: the caller sees everything the service exposes, including what no method serves.
 */
#[AsWorkflow(self::TYPE)]
final class ReserveStockWorkflow
{
    /**
     * The endpoint says *where* the service is served — a deployment matter, not a contract one.
     *
     * It is created by `bin/demo-nexus`, which points it at the shop's namespace and at the queue
     * its worker polls.
     */
    public const ENDPOINT = 'demo-shop-stock';

    /** The name the server knows — the one in `#[AsWorkflow]`, not the FQCN. */
    public const TYPE = 'ReserveStockWorkflow';

    /** @var NexusStub<StockContract> */
    private readonly NexusStub $stock;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->stock = $environment->nexusStub(StockContract::class, endpoint: self::ENDPOINT);
    }

    /**
     * @param array<string, int> $lines reference => quantity
     *
     * @return array{reserved: bool, missing: array<string, int>}
     */
    #[AsWorkflowMethod]
    public function run(string $order, array $lines): array
    {
        return $this->environment->await($this->stock->reserve($order, $lines));
    }
}
