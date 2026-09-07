<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use App\Application\UseCase\PlaceOrder;
use App\Domain\Payment\Money;
use App\Domain\Payment\OrderId;
use App\Infrastructure\Nexus\NexusPayments;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * The shop has an order billed by the business.
 *
 * Both shapes, in the same execution and through the same stub. `verify` comes back right away,
 * answered by a method the business wrote; `charge` is fulfilled by a workflow on the other side,
 * which takes some fifteen seconds. **Nothing tells the two apart**, and after this class was
 * layered that is truer than before: the code that decides no longer knows that either one is a
 * Nexus call.
 *
 * This is a **primary adapter**. Like a controller or a console command it translates something
 * arriving, here three scalars from `durable:demo:bill`, into the application's own types, invokes
 * one use case, and turns the answer back into a payload. Unlike a controller it may be replayed
 * from the top at any time, which is why everything it reaches that touches the outside world goes
 * through a port whose adapter is a Nexus operation.
 *
 * While it waits, this workflow holds nothing open: no connection, no process, no transaction. It
 * is not in memory, and the worker that resumes it may not be the one that started it.
 */
#[AsWorkflow(self::TYPE)]
final class OrderWorkflow
{
    public const TYPE = 'OrderWorkflow';

    private readonly PlaceOrder $placeOrder;

    public function __construct(
        WorkflowEnvironment $environment,
    ) {
        $this->placeOrder = new PlaceOrder(new NexusPayments($environment));
    }

    /**
     * @param int $amount in cents
     *
     * @return array{verified: array{accepted: bool, reason: string|null}, charge: array{receipt: string, charged: int}|null}
     */
    #[AsWorkflowMethod]
    public function run(string $order, int $amount, string $currency = 'EUR'): array
    {
        return ($this->placeOrder)(OrderId::fromString($order), Money::of($amount, $currency))->toWire();
    }
}
