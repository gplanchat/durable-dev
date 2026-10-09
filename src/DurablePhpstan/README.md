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
| `helper()` — without `#[AsActivityMethod]` | ✗ | ✗ |
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

### A stub received as a workflow method argument

`#[Activities(OrderActivities::class)]` tells Durable the contract at run time, but PHPStan cannot
read a type from an attribute. The `@param` docblock is what it reads:

```php
/** @param ActivityStub<OrderActivities> $orders */
#[AsWorkflowMethod]
public function run(
    string $orderId,
    #[Activities(OrderActivities::class)]
    ActivityStub $orders,
    WorkflowEnvironment $env,
): mixed
```

The extension's rule keeps the two in step:

| Identifier | Reported when |
|---|---|
| `durable.activities.contractMismatch` | the docblock names another contract than the attribute |
| `durable.activities.missingGeneric` | the parameter has no `@param ActivityStub<…>`: every call on it would be unknown |

A parent calling such a workflow as a child passes the input arguments only; the extension leaves
the supplied parameters out of the signature it checks.

### A stub that could be a parameter

`durable.activityStubCouldBeParameter` reports a stub built with `$env->activityStub()` that the
workflow method could receive as an `#[Activities]` parameter instead. It reports, it does not
rewrite: the message gives the attribute and the docblock to write, and you move the stub by hand.

```text
Activity stub $orders could be a parameter of run(): #[Activities(OrderActivities::class,
attempts: 3)] ActivityStub $orders, documented with @param ActivityStub<OrderActivities> $orders.
```

It reports a stub built in the workflow method, or built in the constructor and read by that
method only, with no options or with `ActivityOptions::of()` and literal values. It stays silent
when it can see that the move would change what runs:

- options computed at run time, `ActivityOptions::default()`, an `of()` that sets nothing or only
  an empty `nonRetryableExceptions` list (the attribute would build the stub with no options, and
  the Durable worker would retry without backoff), an empty `taskQueue`, an `activityId`, or a
  `backoffCoefficient` or `maximumInterval` the attribute refuses at registration;
- a stub that a signal, update or helper method reads, that the constructor reads after building
  it, or that a closure, an arrow function or an anonymous class reads;
- a local name assigned twice, or one that is already a parameter of the method;
- a class that extends another, implements an interface or uses a trait, any of which may declare
  or call the workflow method.

Code that already fails, or a `nonRetryableExceptions` entry that never matches, is still
reported, with a warning: after the move, the worker refuses to register the workflow. That is the
case for:

- `of(0)`, which fails on every run;
- a `nonRetryableExceptions` entry that is not a `\Throwable` class (an unknown class, or
  `self::class` in a workflow): `of()` accepts it, and it never matches an exception;
- a contract with no `#[AsActivityMethod]` method, which fails on the first call;
- a contract that names no class or interface.

The rule does not see every way code reaches the stub. In these cases a report can be a false
positive, and the stub has to stay:

- another method of the class calls the workflow method, which would then lack the stub;
- `__call` or a variable method name reaches the workflow method;
- reflection, `get_object_vars()` or an `(array)` cast reads the property;
- the local name is used before the stub is built, for example in an `isset()` or a by-reference
  argument.

When the stub must stay where it is, ignore the report in `phpstan.neon`:

```neon
parameters:
    ignoreErrors:
        - identifier: durable.activityStubCouldBeParameter
```

Add a `path:` to scope it, or put `// @phpstan-ignore durable.activityStubCouldBeParameter` on the
line of the call.

### A clock or randomness read in a workflow class

A workflow method is replayed from its journal, so a value it reads from the clock or from a random
source differs on every replay. In a class carrying `#[AsWorkflow]`, the extension reports these
reads with the identifier `durable.nondeterministic`:

- the functions `time()`, `microtime()`, `hrtime()`, `rand()`, `mt_rand()`, `random_int()`,
  `uniqid()`, `sleep()`, `usleep()`, `now()`, `today()`, and `date()` without a timestamp;
- `new` on a class implementing `DateTimeInterface` (`DateTime`, `DateTimeImmutable`, Symfony's
  `DatePoint`, Carbon, your own subclasses) without an argument or with a relative string such as
  `'now'` or `'tomorrow'`, and `::now()`, `::today()`, `::tomorrow()`, `::yesterday()` or
  `::parse()` without an absolute date on those classes;
- `now()`, `sleep()` and `usleep()` on a `Psr\Clock\ClockInterface`.

An absolute date (`'2026-01-01'`), a timestamp (`'@1700000000'`), an argument whose value is not a
constant, and anything inside a closure passed to `$env->sideEffect()` are not reported.

```php
$deadline = new \DateTimeImmutable('+3 days');                                // reported
$deadline = $env->sideEffect(static fn () => new \DateTimeImmutable('+3 days')); // journalled
```

The whole class is checked, signal and query handlers and private helpers included. A helper
class called from the workflow is not: the rule reads the workflow class only.

## What it does not do

A method absent from the contract, or present but without `#[AsActivityMethod]` — respectively
`#[AsWorkflowMethod]` for a child — stays unknown. That is deliberate: the stub already refuses it at
execution time with a `BadMethodCallException`, and the analysis now says so beforehand.

## Licence

MIT.
