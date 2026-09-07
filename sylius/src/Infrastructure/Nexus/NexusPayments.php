<?php

declare(strict_types=1);

namespace App\Infrastructure\Nexus;

use App\Application\Port\Payments;
use App\Domain\Payment\Authorisation;
use App\Domain\Payment\Money;
use App\Domain\Payment\OrderId;
use App\Domain\Payment\Receipt;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingContract;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * The driven adapter: it calls the billing context and translates.
 *
 * The endpoint lives here and not in the port, because it says *where* the service is served. That
 * is a deployment fact, it changes between environments, and the port does not.
 *
 * Everything this class does is plumbing. It unwraps the shop's values onto the wire, awaits, and
 * hands the payload to a factory. The judgement is in those factories, which is why they carry the
 * tests: {@see WorkflowEnvironment} is `final` and the in-memory harness refuses Nexus, so an
 * adapter holding one cannot be faked or run under test. Keeping it this thin is what makes that
 * acceptable.
 */
final class NexusPayments implements Payments
{
    /** The business's endpoint, created by `bin/demo-nexus`. */
    public const ENDPOINT = 'demo-business-billing';

    /** @var NexusStub<BillingContract> */
    private readonly NexusStub $billing;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
        string $endpoint = self::ENDPOINT,
    ) {
        $this->billing = $environment->nexusStub(BillingContract::class, endpoint: $endpoint);
    }

    public function authorise(OrderId $order, Money $amount): Authorisation
    {
        return Authorisation::fromWire($this->payload(
            $this->billing->verify($order->toString(), $amount->cents(), $amount->currency()->code()),
        ));
    }

    public function capture(OrderId $order, Money $amount): Receipt
    {
        return Receipt::fromWire($this->payload(
            $this->billing->charge($order->toString(), $amount->cents(), $amount->currency()->code()),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(mixed $operation): array
    {
        $answer = $this->environment->await($operation);

        if (!\is_array($answer)) {
            throw new \UnexpectedValueException(\sprintf('A Nexus operation answered %s where the contract declares an array. The payload travels as plain JSON, so this means the other side changed its return shape.', get_debug_type($answer)));
        }

        return $answer;
    }
}
