# `gplanchat/durable-phpstan`

PHPStan extension for [`gplanchat/durable`](https://github.com/gplanchat/durable). It resolves the
calls of `ActivityStub`, `ChildWorkflowStub` and `NexusStub` from their typed contract.

> **Read-only mirror.** This repository is a subtree-split of
> **[gplanchat/durable-dev](https://github.com/gplanchat/durable-dev)**, published so Composer can
> require this package on its own. Issues and pull requests are disabled here — open them **[on the
> monorepo](https://github.com/gplanchat/durable-dev/issues)**.
>
> **The tests are in the monorepo, not here.** This split carries source only. What covers it is
> `tests/unit/DurablePhpstan/` in the monorepo, run by its `unit` suite.
>
> **Documentation**: [durable.rocks](https://durable.rocks).

For `NexusStub`, it also follows **inheritance**: a Nexus contract splits into two interfaces — the
one the handler implements and the one that extends it for the caller —, and the stub calls both of
them.

```bash
composer require --dev gplanchat/durable-phpstan
```

With [`phpstan/extension-installer`](https://github.com/phpstan/extension-installer), nothing more.
Otherwise, in your `phpstan.neon`:

```neon
includes:
    - vendor/gplanchat/durable-phpstan/extension.neon
```

## The problem

The stubs resolve their calls through `__call()`. Without an extension, PHPStan sees nothing but
objects with no methods and reports **every** stub call — the correct ones as well as the faulty:

```php
$this->orders->charge($orderId, 100);   // without the extension: "undefined method" — false
$this->orders->chrage($orderId, 100);   // without the extension: "undefined method" — true
```

The flaw, then, is not the silence, it is the **noise**. Four errors of which two are false go into
a baseline or get ignored in one block, and the two true ones leave along with them — which comes to
the same thing as checking nothing at all, only more expensive.

Since decisions **DUR038** and **DUR039**, the typed stub is the *only* way to schedule an activity
or a child workflow. That check is therefore the only one left.

## What the extension brings

On the same fixture, measured:

| | without the extension | with |
|---|---|---|
| `charge()` — correct call | ✗ wrongly reported | ✓ |
| `run()` — child, correct call | ✗ wrongly reported | ✓ |
| `chrage()` — typo | ✗ | ✗ |
| `helper()` — without `#[ActivityMethod]` | ✗ | ✗ |
| `charge($id)` — one argument out of two | *invisible* | ✗ **arity checked** |

The last row is the gain the noise was masking: once the method is known, PHPStan compares the
arguments against what the contract declares.

## What it requires of your code

The stub carries its contract as a generic parameter, and PHPStan infers it from
`WorkflowEnvironment::activityStub()`. It still has to be able to follow it all the way to the call
site — which is the case as soon as the property is `readonly` and assigned once, in the
constructor:

```php
private readonly ActivityStub $orders;   // enough

public function __construct(WorkflowEnvironment $environment)
{
    $this->orders = $environment->activityStub(OrderActivities::class);
}
```

A **mutable** property loses the parameter between the constructor and the method. Annotate it
explicitly in that case:

```php
/** @var ActivityStub<OrderActivities> */
private ActivityStub $orders;
```

In both cases, if the contract stays untraceable, the call is simply **unknown** to PHPStan rather
than accepted blindly: better a false positive than a check that has been silently switched off.

## What it does not do

A method absent from the contract, or present but without `#[ActivityMethod]` — respectively
`#[WorkflowMethod]` for a child — stays unknown. That is deliberate: the stub already refuses it at
execution time with a `BadMethodCallException`, and the analysis now says so beforehand.

## Licence

MIT.
