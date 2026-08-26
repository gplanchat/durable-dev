# DUR034 — A signal name is a backed enum, and the wire keeps the string

## Status

Accepted

## Context

A signal is addressed by name, and the name was a bare `string` on both sides of the exchange:
`WorkflowEnvironment::waitSignal('approve')` on the workflow side, `WorkflowClient::signal($id,
'approve')` on the emitter side.

A mistyped signal name produces **no error anywhere**. The emitter sends `aprove`, the server
records it, and the wait for `approve` is never settled. The execution simply stays suspended, with
nothing in the logs and nothing in history that reads as a fault — the two names are both perfectly
valid, and neither side has any way to know the other meant the same signal. This is the failure
mode this codebase already treats as the most expensive to diagnose. `TaskQueue`'s docblock names
it (in French, hence paraphrased here): a mis-named queue produces no error, the work is dropped
there and nobody ever comes for it, and the execution simply waits with nothing in the logs.

That docblock answers it as far as a value object can, and is equally explicit about where that
stops: it does not catch the typo that is still a valid name — `durable-activites` for
`durable-activities` — and only a registry of the queues actually served could.

For a signal, that registry exists and is cheap: a workflow's signal surface is finite, known at
authoring time, and already written down in the workflow class.

## Decision

### The name is given as a `\BackedEnum`

```php
enum OrderSignal: string
{
    case Approve = 'approve';
    case Cancel  = 'cancel';
}

$env->waitSignal(OrderSignal::Approve);
$client->signal($workflowId, OrderSignal::Approve, ['by' => $user]);
```

A string-backed enum **is** the registry `TaskQueue` names and cannot provide. It enumerates a
workflow's signal surface in one declaration, and it moves the typo from a wait that never settles
to a parse error: `OrderSignal::Aprove` does not exist, and nothing runs.

This is deliberately not a `SignalName` value object in the shape of `TaskQueue`. Such an object
would validate emptiness, whitespace and control characters — none of which is the failure being
addressed. `SignalName::named('aprove')` is a perfectly valid signal name, and it deadlocks exactly
as before. The enum is the only construction that makes the two sides agree by construction rather
than by convention.

### The bare string stays accepted

The signature is `\BackedEnum|string`, not `\BackedEnum`.

A signal is an *external* message by definition: it arrives from a `curl`, from the Temporal CLI,
from a scheduler, from a service written in another language. That boundary cannot be typed, and
requiring an enum would amount to requiring that every emitter be PHP and share the library's
classes. The enum types the intent of callers who have one; the string keeps the door open for
those who do not.

### Coercion happens once, at the boundary, and the wire keeps the string

Each entry point reads `->value` immediately and passes a `string` on:

```php
public function waitSignal(\BackedEnum|string $signalName, ...): array
{
    $signalName = $signalName instanceof \BackedEnum ? (string) $signalName->value : $signalName;
```

Nothing downstream ever sees an enum: not the history source, not the command buffer, not the
journal, not the Temporal request. This is DUR031's rule applied to a name rather than to an
options object — the value object crosses the port, the **wire** stays what it already was.

Keeping the wire a string is not a concession, it is required. History is read by `temporal
workflow show`, by other languages, and by replays of executions started before this change. A
recorded signal named `approve` must settle a wait declared as `OrderSignal::Approve`, and it does,
because by the time the slot is looked up both are the same string.

`DeliverWorkflowSignalMessage` makes the same choice one layer up, and says why in the property
that holds it: the message is serialized by Messenger and travels as data, so it stores the backed
value, never the enum. The emitter still types its intent at the call site.

### Where it applies, and where it stops

Applied: `WorkflowEnvironment::waitSignal()`, `WorkflowClientInterface::signal()` and its
implementation, `DeliverWorkflowSignalMessage`.

Not applied, for now: `query()`, `update()`, `waitUpdate()`, `registerQueryHandler()`, and the
`#[SignalMethod(name:)]` attribute. Queries and updates have the same shape and the same failure
mode, and each would cost one parameter to widen — the same deferral DUR032 made for a deadline on
`waitUpdate()`. The attribute is a special case worth naming: PHP does allow an enum case as an
attribute argument, since it is a constant expression, so the obstacle is only that
`SignalMethod::__construct()` still types its `$name` as `string`. Widening it is one edit, left
out so that the declaration side and the waiting side move in one deliberate step rather than
two half-steps.

## Consequences

- **BREAKING for implementers of `WorkflowClientInterface`.** Widening a parameter type in an
  interface is a fatal error at class-declaration time for any implementation that kept the narrow
  one — not a deprecation, not a runtime failure on call. Every implementation must be widened in
  the same change.
- That break is not hypothetical, and it escaped local verification: the anonymous double in
  `symfony/tests/Unit/DurableSampleWorkflowRunnerRoutingTest.php` still declared `string
  $signalName`, and the root `phpunit.xml` does not run the `symfony/` suite. The failure surfaced
  only in the `App exemple Symfony` CI job, on all four PHP versions at once. **A change to a public
  interface must be verified against the sample application, not only against the library suite.**
- No history migration and no replay hazard. The recorded name was a string before and is the same
  string after; an execution started before this change replays unchanged.
- A workflow whose signals are declared as an enum gains nothing against an emitter that does not
  use it. The guarantee is one-sided by construction, and the documentation must not present it as
  an agreement between the two sides.
- `DeliverWorkflowSignalMessage::$signalName` is no longer a promoted constructor property, but it
  remains `public string` with the same name, so Messenger serialization and every reader are
  unaffected.

## Alternatives considered

- **A `SignalName` value object, as for `TaskQueue`.** It validates shape, which is not the failure.
  The typo that stays a valid name passes it, and that is the whole problem.
- **A library interface, `enum OrderSignal: string implements SignalName`.** It would let the
  library state the intent in a type, but the coercion is `->value` either way and the enum's own
  type already does the work. It buys nothing and forces every consumer to import a marker.
- **Accepting only `\BackedEnum`.** It would make the guarantee two-sided, at the cost of excluding
  every emitter that is not PHP — which is most of the reason signals exist.
- **Coercing late, keeping the enum through the core.** It would put a PHP-only type into the
  journal path and into both drivers, for a benefit that ends at the call site. DUR031 already
  settled who owns the wire.

## Related decisions

- **DUR013** — workflow modelling and the Query / Signal / Update surface.
- **DUR031** — value objects across the ports, and who owns the wire.
- **DUR018** — event parity, replay, and signal slots.
- **DUR032** — a deadline on a signal wait.
