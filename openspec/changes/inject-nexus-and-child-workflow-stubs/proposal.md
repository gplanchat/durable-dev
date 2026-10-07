## Why

A workflow method can receive its activity stubs as arguments:

```php
/** @param ActivityStub<OrderActivities> $orders */
#[AsWorkflowMethod]
public function run(
    string $orderId,
    #[Activities(OrderActivities::class, attempts: 3)] ActivityStub $orders,
    WorkflowEnvironment $env,
): string
```

The documentation now shows that form first, on every host (#779). The two other stubs a workflow
uses cannot be written that way. A Nexus stub and a child workflow stub are still built by hand,
in the constructor or at the top of the method:

```php
public function __construct(private readonly WorkflowEnvironment $environment)
{
    $this->stock = $environment->nexusStub(StockContract::class, endpoint: self::ENDPOINT_STOCK);
}
```

So a workflow that calls an activity, a Nexus operation and a child mixes two styles, and the
page that explains when to build a stub yourself has to list "Nexus and child workflow stubs" as a
case of its own. The loader already has one place that decides which parameters it supplies
(`WorkflowDefinitionLoader::isInjected()`). Every reader of a workflow signature consults it: the
input a caller passes, the parameter names a fulfilling workflow must share with its Nexus contract,
the payload a parent sends to a child, and the parameters PHPStan checks on a call through a child
stub. Extending it covers the four of them at once.

Two things keep this from being a copy of `#[Activities]`:

- **A Nexus endpoint is a deployment fact.** `WorkflowEnvironment::nexusStub()` says so in its
  docblock: the endpoint "changes from one environment to the next, while the contract does not".
  An attribute argument is a constant. Writing the endpoint into the attribute would freeze in the
  code what the docblock says belongs to the deployment.
- **A child's workflow id often depends on the input.** `"ship-{$orderId}"` makes a child
  findable and deduplicates it through `WorkflowIdReusePolicy`. `ChildWorkflowStub` takes its
  options once, at construction, and has no per-call setting. Without one, an injected child stub
  would only serve children whose id Durable generates.

## What Changes

- **Nexus stubs as arguments.** A parameter typed `NexusStub` and marked with a new attribute
  naming the contract receives a Nexus stub. The attribute MAY name the endpoint. When it does not,
  the endpoint is read from the host's configuration, which maps a contract to an endpoint. The
  attribute also takes the operation bounds as seconds.
- **Endpoint configuration on every host.** Symfony: `durable.nexus.endpoints` in
  `config/packages/durable.yaml`. Laravel: `nexus.endpoints` in `config/durable.php`, beside
  `nexus.handlers`. Magento: a `nexusEndpoints` argument of the runtime in `di.xml`, beside
  `nexusHandlers`. `$env->nexusStub()` reads the same configuration when it is called without an
  endpoint, so the explicit form gains the same fallback.
- **Child workflow stubs as arguments.** A parameter typed `ChildWorkflowStub` and marked with a
  new attribute naming the child workflow class receives a child stub. The attribute takes the
  options that are constants: parent close policy, id reuse policy, task queue, namespace, the
  three workflow timeouts in seconds. The journal backends honour the first two; the others fail
  registration there until the options-parity change that PR #782 announces. Cron schedule, static
  summary and static details are left out (design.md).
- **A per-call workflow id.** `ChildWorkflowStub::withWorkflowId(string)` returns a stub that
  starts the child under that id. The stub it is called on is unchanged.
- **Errors at registration.** A child option the backend in use does not honour, an endpoint that
  neither the attribute nor the configuration names,
  an attribute on a parameter of another type, a class that is not a workflow, or a child whose
  entry method is named `withWorkflowId` fails when the workflow that declares the parameter is
  registered, naming the parameter.
- **PHPStan.** The rule that checks `#[Activities]` against its `@param` docblock covers the two
  new attributes. The extension already resolves the generics of both stubs.
- **Documentation**, EN and FR: the Nexus and child pages show the argument form first; "When to
  build the stub yourself" loses its Nexus and child case; the configuration pages document the
  endpoint keys.

### Not in scope

- **The Rector migration** from the Temporal PHP SDK: #778 covers activity stubs. Nexus and child
  stubs follow once this change lands.
- **Per-call options beyond the workflow id.** Memo and search attributes computed at run time
  keep the explicit `$env->childWorkflowStub($class, $options)` form. See design.md.
- **Any change on the wire.** The commands sent to Temporal and the events journaled are the ones a
  hand-built stub produces today.

## Impact

- `src/Durable`: two attributes, the loader's argument planning, an endpoint resolver port,
  `ChildWorkflowStub::withWorkflowId()`.
- `src/DurableBundle`, `src/DurableLaravel`, `src/DurableModule`: the endpoint configuration and
  its wiring.
- `src/DurablePhpstan`: the parameter rule.
- `documentation/user/`: `nexus`, `workflows`, `configuration`, EN and FR.
- Public API: two new attributes, one new stub method, three new configuration keys. Nothing is
  removed or deprecated; the constructor form keeps working.
