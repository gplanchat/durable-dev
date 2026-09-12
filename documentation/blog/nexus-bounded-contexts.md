---
title: "The Nexus stub is an adapter, not a port"
date: 2026-09-07
weight: 10
---

# The Nexus stub is an adapter, not a port

Most of us learned the rule and repeat it without re-deriving it: a bounded context does not call
another bounded context synchronously. Herberto Graça states it in [Explicit
Architecture](https://herbertograca.com/2017/11/16/explicit-architecture-01-ddd-hexagonal-onion-clean-cqrs-how-i-put-it-all-together/),
where components stay decoupled to the point that "a component has no direct knowledge of any other
component", and where they must talk he reaches for events and eventual consistency.

The reason is availability. B goes down, you fail. B slows, you slow. B's deploy is your incident.
Over HTTP that coupling is inseparable from the call, because the response has to arrive while you
still hold the socket.

A durable call breaks that link, and [the four-application
demonstration](/docs/use-cases/nexus-demo/) is where we measured it: a worker stayed off for
four minutes, the operation sat at `NEXUS_OPERATION_STARTED`, the caller consumed nothing, and both
sides finished when the worker came back. That page also carries the context map, the nine-second
budget that separates a boundary-crossing query from a saga step, and the ordering rule that
compensation reduces to. This post is about the part it does not cover: where the call belongs in
your own code.

## The flat version, and why it was a counter-example

Here is the shop's order workflow as it stood until recently:

```php
#[AsWorkflow(self::TYPE)]
final class OrderWorkflow
{
    public const ENDPOINT = 'demo-business-billing';

    /** @var NexusStub<BillingContract> */
    private readonly NexusStub $billing;

    public function __construct(private readonly WorkflowEnvironment $environment)
    {
        $this->billing = $environment->nexusStub(BillingContract::class, endpoint: self::ENDPOINT);
    }

    #[AsWorkflowMethod]
    public function run(string $order, int $amount, string $currency = 'EUR'): array
    {
        $verdict = $this->environment->await($this->billing->verify($order, $amount, $currency));

        if (true !== ($verdict['accepted'] ?? false)) {
            return ['verified' => $verdict, 'charge' => null];
        }

        return [
            'verified' => $verdict,
            'charge' => $this->environment->await($this->billing->charge($order, $amount, $currency)),
        ];
    }
}
```

That class is flat on purpose and it proves what it was written to prove: the two operations look
identical at the call site. A method someone wrote answers `verify`. A workflow that takes twelve
seconds answers `charge`. The code above cannot tell you which is which.

As architecture it is a counter-example. `BillingContract` is another context's wire format.
`$verdict['accepted']` is another context's data structure. Both walked into the layer that holds
the shop's business decision.

## The version that respects the hexagon

Put the stub in a driven adapter. The port belongs to your application core and speaks your
context's language:

```php
namespace Shop\Application\Port;

interface Payments
{
    public function authorise(OrderId $order, Money $amount): Authorisation;

    public function capture(OrderId $order, Money $amount): Receipt;
}
```

The adapter implements it by calling the stub and translating:

```php
namespace Shop\Infrastructure\Nexus;

final class NexusPayments implements Payments
{
    /** @var NexusStub<BillingContract> */
    private readonly NexusStub $billing;

    public function __construct(private readonly WorkflowEnvironment $environment)
    {
        $this->billing = $environment->nexusStub(BillingContract::class, endpoint: 'demo-business-billing');
    }

    public function authorise(OrderId $order, Money $amount): Authorisation
    {
        $verdict = $this->environment->await(
            $this->billing->verify($order->toString(), $amount->cents(), $amount->currency()->code()),
        );

        return true === ($verdict['accepted'] ?? false)
            ? Authorisation::granted()
            : Authorisation::refused(RefusalReason::fromWire($verdict['reason'] ?? null));
    }

    public function capture(OrderId $order, Money $amount): Receipt
    {
        $receipt = $this->environment->await(
            $this->billing->charge($order->toString(), $amount->cents(), $amount->currency()->code()),
        );

        // The contract returns `array{receipt: string, charged: int}`: an amount, and no currency.
        // `Cents` exists for that reason and `Money` will not do. Rebuilding the currency from the
        // one we asked for would assume an answer the other context never gave.
        return new Receipt(
            ReceiptNumber::fromString($receipt['receipt']),
            Cents::of($receipt['charged']),
        );
    }
}
```

Two arrays appear in that class and they go no further. `$verdict` and `$receipt` are the wire, and
`NexusPayments` is the last place in the shop that reads one by key. Above it, `Authorisation`,
`RefusalReason`, `Receipt`, `ReceiptNumber` and `Cents` carry the same facts with types. The
contract keeps returning `array` because plain JSON is what crosses the boundary, and that array
stops at the adapter.

The use case knows none of it:

```php
namespace Shop\Application\UseCase;

final readonly class PlaceOrder
{
    public function __construct(private Payments $payments) {}

    public function __invoke(OrderId $order, Money $amount): OrderOutcome
    {
        $authorisation = $this->payments->authorise($order, $amount);

        if (!$authorisation->isGranted()) {
            return OrderOutcome::refused($authorisation->reason());
        }

        return OrderOutcome::paid($this->payments->capture($order, $amount));
    }
}
```

## What falls out of the translation

**You cannot skip the anti-corruption layer.** The contract returns `array` and the wire carries
plain JSON, so something has to build `Authorisation` and `Receipt` out of
`['accepted' => bool, 'reason' => ?string]`. That translation is the ACL, and it lives in one file.
When the other context changes its payload, one adapter breaks and your use cases do not.

**The endpoint belongs to deployment.** `demo-business-billing` names where the service is served.
It changes between environments and has no business in a port signature.

**Typing the wire exposes what the wire omits.** `RefusalReason::fromWire()` gives a free-text
reason a type, and `charge` returns an amount with no currency, which is why the adapter builds a
`Cents` and refuses to pretend it has a `Money`. Both gaps show up because something has to
translate. The flat version read `$verdict['accepted']` straight into a business decision, and no
reviewer caught either one.

**You can test the use case without a cluster.** `Payments` has an in-memory implementation, and
`PlaceOrder` cannot tell whether the money moved next door or across a Nexus endpoint.

If you need two operations in flight at once, have the port return an awaitable of your own type
rather than the resolved value. `await()` is the only wait, so the shape stays honest and the
scheduling stays yours.

## The caveat that belongs to durable execution

The workflow class is a *primary* adapter, a delivery mechanism like a controller or a console
command, with one unusual property: a worker may replay it from the beginning at any time. So
everything your use case reaches for that touches the outside world has to go through a port whose
adapter is an activity or a Nexus operation. A port implemented as "call the HTTP client right
here" will re-execute on replay. Hexagonal discipline is what makes replay safe.

## Where this stands

The shop ships this now. `sylius/src/Domain/Payment/` holds the values, `Application/Port/Payments`
the port, `Application/UseCase/PlaceOrder` the rule about asking before committing, and
`Infrastructure/Nexus/NexusPayments` the adapter. The snippets above are trimmed for reading: the
real adapter guards against an answer that is not an array at all, and the real workflow is four
lines because everything else moved.

The other three mockups still call their stubs from workflow code, and
[the demonstration's page](/docs/use-cases/nexus-demo/) says so. The arrangement is demonstrated
once, not proven to hold across hosts.

What the demonstration does prove is the premise: the reason you were given for not making the call
is gone, and the reasons you were not given are still yours to handle.
