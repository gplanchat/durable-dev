# gplanchat/durable-rector

Rector rules for projects that consume [`gplanchat/durable`](https://github.com/gplanchat/durable-dev)
and have code to migrate. Two sets, for two different migrations.

> **Read-only mirror.** This repository is a subtree-split of
> **[gplanchat/durable-dev](https://github.com/gplanchat/durable-dev)**, published so Composer can
> require this package on its own. Issues and pull requests are disabled here — open them **[on the
> monorepo](https://github.com/gplanchat/durable-dev/issues)**.
>
> **The tests are in the monorepo, not here.** This split carries source only. What covers it is
> `tests/unit/DurableRector/` in the monorepo, run by its `rector` suite.
>
> **Documentation**: [durable.rocks](https://durable.rocks).

```bash
composer require --dev gplanchat/durable-rector
```

| Set | For |
|---|---|
| `temporal-sdk.php` | Coming **from the official Temporal PHP SDK**, keeping the workflow and activity type names a running server already knows |
| `durable-upgrade.php` | Already on Durable, **moving from one version to the next** — cumulative, and detailed version by version in [`UPGRADE.md`](https://github.com/gplanchat/durable-dev/blob/main/UPGRADE.md) |

```php
// rector.php
return Rector\Config\RectorConfig::configure()
    ->withImportNames()   // or the rewritten names land fully qualified, next to a stale `use`
    ->withSets([__DIR__ . '/vendor/gplanchat/durable-rector/config/sets/temporal-sdk.php']);
```

---

## Upgrading inside Durable

The table above brings a project **into** Durable, once. `durable-upgrade.php` moves it **forward**
in there, on every upgrade:

```php
// rector.php
return Rector\Config\RectorConfig::configure()
    ->withImportNames()
    ->withSets([__DIR__ . '/vendor/gplanchat/durable-rector/config/sets/durable-upgrade.php']);
```

It is cumulative — running it once catches up every version crossed. What it contains, and above
all what it **cannot** do on its own (a compiled Symfony container holds the fully qualified names,
and wants its `cache:clear`), is written version by version, at the root of the repository, in
[`UPGRADE.md`](https://github.com/gplanchat/durable-dev/blob/main/UPGRADE.md).

### Activity stubs become `#[Activities]` parameters

`ActivitiesParameterRector` is not tied to a break. It moves a stub built with
`$environment->activityStub()` to the form the documentation shows first: an `ActivityStub`
parameter of the `#[AsWorkflowMethod]` method, marked `#[Activities(Contract::class, ...)]`, plus
the `@param ActivityStub<Contract>` docblock that `gplanchat/durable-phpstan` reads. Durable passes
that stub in when it runs the workflow, and the reader sees the contract and its options in the
signature.

It rewrites a `final` class that uses no trait, and in it two shapes: a local variable assigned once, by a
statement directly in the body of the workflow method (not nested in an `if` or a loop), and a
private property assigned once in the constructor and read only by that method. The contract must be
a `Contract::class` constant. The options must be absent, or `ActivityOptions::of()` with literal
arguments only, because an attribute argument is a constant.

Two things change for a rewritten class, and the [UPGRADE](https://github.com/gplanchat/durable-dev/blob/main/UPGRADE.md)
entry says what to do about them:

- **The workflow method gains a required parameter.** Durable supplies it, but a test that calls
  the method directly must now pass a stub.
- **The options are checked at registration**, not when the activity is scheduled. The workflow no
  longer registers when `backoffCoefficient` is under 1, when `maximumInterval` is shorter than
  `initialInterval` (1 second when not given), when a `nonRetryable` class is not a `\Throwable`, or
  when the contract does not exist or declares no `#[AsActivityMethod]`.

The rule writes the new parameter's type and attribute fully qualified, and leaves imports it made
useless, such as `ActivityOptions`, in place. `->withImportNames(removeUnusedImports: true)` in your
`rector.php` shortens the first and removes the second.

It keeps the constructor form, which stays supported, wherever the attribute cannot carry the stub,
or where the rewrite could change what runs:

- **options the attribute cannot say the same way**: options computed at run time, such as a task
  queue named after a tenant; `of()` arguments given as value objects (`RetryLimit::once()`,
  `Duration::seconds()`), as an exception class written as a string, as an empty task queue, or as
  an `activityId`, which the attribute has no field for; and `ActivityOptions::default()` or an
  `of()` that sets nothing (no argument, only `null`, or an empty exception list), because a bare `#[Activities]` builds the stub with no options at all,
  and the Durable worker then retries a failed activity without backoff;
- **a stub read outside the workflow method**: Durable calls a signal or update method with the
  message payload only, and a helper method would need the stub passed in. A read inside a
  `function () {}` closure or an anonymous class counts too, and so does, anywhere in the class, a
  dynamic `$this->{$name}` or a fetch of the property on another receiver (`$self->orders` after
  `$self = $this`, or `$other->orders` on another instance);
- **a variable with the stub's name** in the workflow method, such as an arrow function parameter,
  a caught exception or a destructuring: it would take the stub's place;
- **a class that is not `final`, that uses a trait, or that calls its own workflow method**: a
  subclass may override the method, a trait may call it or declare it abstract, and a call from
  inside the class would miss the new parameter;
- **a workflow method declared by an interface or a parent class**: PHP forbids the implementation
  from adding a required parameter. A workflow migrated with `temporal-sdk.php` usually still
  implements its SDK contract, so it keeps the stub that `TemporalFacadeToEnvironmentRector` wrote
  in the constructor until you drop that interface.

---

## `temporal-sdk.php` — coming off the SDK

### What it does

| Rule | What moves |
|---|---|
| `ActivityContractAttributesRector` | `#[ActivityInterface(prefix:)]` → `#[AsActivity(name:)]`, and every public method gets an explicit `#[AsActivityMethod(name:)]` |
| `WorkflowClassAttributesRector` | `#[WorkflowInterface]` → `#[AsWorkflow(name:)]`, and the four method attributes (`#[AsWorkflowMethod]`, `#[AsSignalMethod]`, `#[AsQueryMethod]`, `#[AsUpdateMethod]`) are **copied from the interface onto the implementing class**, where Durable reads them |
| `RenameClassRector` (configured) | The three SDK failures with a Durable counterpart |
| `TemporalFacadeToEnvironmentRector` | The static facade becomes an injected `WorkflowEnvironment`, `yield` goes, and the `\Generator` return type with it |
| `UnmigratableTemporalCallRector` | Comments every call the migration **cannot** make, and changes nothing else |

### Why the names are the whole point

Both engines derive a type name, and **they derive it differently**:

- The SDK's activity type is `prefix . (name ?? methodName)` — one concatenation, no separator
  inserted. Durable's is `AsActivity::$name . '.' . AsActivityMethod::$name`, and the dot is not
  optional. The two agree on exactly two prefixes: the empty one, and one ending in a dot. **On any
  other prefix this rule changes nothing** and leaves the SDK attribute in place, rather than rename
  an activity that has runs in flight.
- The SDK's workflow type is `#[WorkflowMethod(name:)]` if given, Durable's `#[AsWorkflow(name:)]`;
  both are *optional*, the SDK falls back to the **interface's** short name, Durable to the
  **class's**. A class migrated without an explicit name therefore compiles, passes its tests, and
  stops resolving every run already started. The rule always writes the name out — and over
  [`temporalio/samples-php`](https://github.com/temporalio/samples-php), 24 of the 27 names it
  writes are ones the fallback would have got wrong.
- Every public method of an `#[ActivityInterface]` is an activity for the SDK; under `#[AsActivity]`,
  only a method carrying `#[AsActivityMethod]` is. Methods that carried no `#[ActivityMethod]` get an
  `#[AsActivityMethod]`, named after themselves.

### It adds, it never removes

The SDK attributes stay on the interface. A rule cannot read an attribute another rule has just
deleted in the same pass, and leaving them costs nothing — Durable ignores them, and
`composer remove temporal/sdk` is the honest forcing function for the cleanup.

### The execution model

`Workflow::` is static and `$this->environment` is not, so the rule adds a promoted
`WorkflowEnvironment` constructor parameter — prepended, because Durable resolves the constructor by
**type**, and prepending never puts a required parameter after an optional one.

**`yield` is what says whether a call waits, and it is the only thing that says it.** `yield
Workflow::timer($d)` waits, so it becomes `sleep($d)`; a bare `Workflow::timer($d)` handed to a race
assembles, so it becomes `timer($d)`. `yield $stub->charge()` becomes `await($stub->charge())`,
because a stub assembles and `await()` is the only wait. `Promise::all($p)` becomes `all(...$p)` —
one iterable on that side, variadic on this one — and `Promise::some($p, 2)` becomes `some(2, ...$p)`.

**Two arities it refuses.** The SDK's `Workflow::await(...$conditions)` is variadic and settles on
the first condition; Durable's second parameter is a **deadline**. One condition maps —
`awaitWithTimeout($t, $c)` becomes `await($c, $t)` — and more than one does not, because rewriting
it would quietly turn a second condition into a timeout. Those call sites are left exactly as they
are and reported instead.

**A `callable` that is not a `\Closure`.** The SDK takes `callable` where Durable takes `\Closure`,
so `Workflow::sideEffect([$this, 'compute'])` rewrites to a `TypeError` on first run. It fails
loudly rather than silently, so the rule does not refuse it — but an array or string callable is
worth grepping for before you run the workflow.

**Return types are removed, never written.** A de-yielded method may not keep `\Generator`; what it
actually returns, the SDK could not declare and this rule will not guess. An interface that declared
`\Generator` loses it too — otherwise the class would widen its own contract, which is fatal.

**Two things it refuses to touch.** A **static** method has no `$this`: it gets a marker, not a
rewrite. And a class that is not workflow code is left alone entirely — `yield` is ordinary PHP, and
an interceptor in the official samples yields reflection attributes out of a plain iterator. A class
qualifies by implementing an SDK `#[WorkflowInterface]` contract (what `#[AsWorkflow]` replaces) or
by calling the facade. Inside one
that does, every non-static method is rewritten, helpers included: an SDK workflow is
generator-coloured throughout, which is the problem being removed. The one shape to check by hand
afterwards is a plain iterator generator living inside a workflow class.

### The report: what cannot be migrated at all

`UnmigratableTemporalCallRector` writes a `durable-rector:` comment above any statement calling a
`Workflow::` method Durable has no answer for, and leaves the code untouched. It answers the
question that comes *before* the migration — a workflow built on `Workflow::async()` and
`Workflow::runLocked()` is a redesign, not a long rewrite — and `git checkout` undoes it.

It works from an **allow-list**: seven facade methods are recognised as ones the execution-model
half will rewrite (`newActivityStub`, `newChildWorkflowStub`, `await`, `awaitWithTimeout`, `timer`,
`sideEffect`, `continueAsNew`), and **everything else is reported**. `Workflow::` carries some forty
static methods and `WorkflowEnvironment` answers eight; a deny-list would pass in silence every one
nobody enumerated, the next SDK release included.

It also reports the **options objects** — `ActivityOptions`, `RetryOptions`,
`ChildWorkflowOptions`, `ContinueAsNewOptions`, `LocalActivityOptions` — in the same pass that
rewrites the call around them. `ActivityOptions::new()->withStartToCloseTimeout(…)` has no
counterpart in `ActivityOptions::of()` over `ActivityTimeouts` and `RetryLimit`; rewritten silently,
the result would read as migrated and could not run.

Run against [`temporalio/samples-php`](https://github.com/temporalio/samples-php), the whole set
changes **58 files** — and it reports
coroutines (`async`, `asyncDetached`), the mutex (`runLocked`, `Mutex`), run introspection
(`getInfo`, `getCurrentContext`, `isReplaying`), the saga helper, activity-by-name, in-run search
attributes, and the options objects.

## What it does not do

**Write a return type.** The `\Generator` goes; nothing replaces it. Declaring what a migrated
method returns is yours, and the contract's docblock is usually where it is written down.

**Migrate the options objects, interceptors, or a Saga.** It reports them. See
[OST004 §6](https://github.com/gplanchat/durable-dev/blob/main/documentation/ost/OST004-what-is-not-built-yet.md).

**Anything with no counterpart** — it reports those rather than pretending. `Workflow::getVersion()`
has no target at all until workflow versioning lands; `Workflow::newUntypedActivityStub()` and
activity-by-name calls were removed on purpose
([DUR039](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR039-workflow-authoring-surface.md)).

## Development

`temporal/sdk` is **not** a dependency, of this package or of the monorepo — see
[DUR006](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md). Rector
matches attributes by fully-qualified name and never loads them, so the tests declare the shape they
read in `tests/unit/DurableRector/Source/temporal-sdk-stubs.php`, under the SDK's own namespace.

```bash
vendor/bin/phpunit --testsuite unit --filter DurableRector
```
