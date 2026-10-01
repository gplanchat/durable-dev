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

---

## `temporal-sdk.php` — coming off the SDK

### What it does

| Rule | What moves |
|---|---|
| `ActivityContractAttributesRector` | `#[ActivityInterface(prefix:)]` → `#[AsActivity(name:)]`, and every public method gets an explicit `#[AsActivityMethod(name:)]` |
| `WorkflowClassAttributesRector` | `#[WorkflowInterface]` → `#[AsWorkflow(name:)]`, and the four method attributes (`#[AsWorkflowMethod]`, `#[AsSignalMethod]`, `#[AsQueryMethod]`, `#[AsUpdateMethod]`) are **copied from the interface onto the implementing class**, where Durable reads them |
| `RenameClassRector` (configured) | The three SDK failures with a Durable counterpart. The other four are marked, see below |
| `TemporalFacadeToEnvironmentRector` | The static facade becomes an injected `WorkflowEnvironment`, `yield` goes, and the `\Generator` return type with it |
| `UnmigratableTemporalCallRector` | Comments every call the migration **cannot** make, and every reference to an SDK failure with no counterpart, and changes nothing else |

### Why the names are the whole point

Both engines derive a type name, and **they derive it differently**:

- The SDK's activity type is `prefix . (name ?? methodName)`: one concatenation, no separator
  inserted. Durable's is `AsActivity::$name . '.' . AsActivityMethod::$name` when the contract name
  is not empty, and `AsActivityMethod::$name` alone when it is empty. The rule carries over the
  empty prefix and any literal prefix ending in a dot with something before it: `'Order.'` becomes
  `#[AsActivity(name: 'Order')]`, `'Billing.Order.'` becomes `#[AsActivity(name: 'Billing.Order')]`,
  and both engines name the activity `Billing.Order.charge`. **On any other prefix, this rule
  changes no attribute and adds a `durable-rector:` marker above the interface.** That covers three
  cases:
  - a computed prefix (a constant, a concatenation): the rule reads a prefix only when it is a
    string literal;
  - a prefix that does not end in a dot (`'Order'`): the SDK type of `charge()` is `Ordercharge`,
    and a non-empty contract name always adds a dot before the method name;
  - the prefix `'.'` alone: the SDK type is `.charge`, and an empty contract name gives `charge`.

  The SDK attribute stays in place rather than rename an activity that has runs in flight. A method
  whose SDK `name:` is not a string literal cannot get its `#[AsActivityMethod]`, so the whole
  contract stays as it is and the marker goes above that method. When that method also names one of
  the four SDK failures without a Durable counterpart in a parameter or return type, the failure
  marker sits next to it.

  To migrate a marked contract by hand, give each activity the old SDK type as its full Durable
  name. An empty `#[AsActivity(name: '')]` with the full SDK type in `#[AsActivityMethod(name:)]`
  reproduces any prefix: for the prefix `'Order'`, `#[AsActivityMethod(name: 'Ordercharge')]` on
  `charge()` keeps the type `Ordercharge`.
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
`yield Workflow::getVersion($changeId, $min, $max)` becomes `version($changeId, $min, $max)`, with
no `await()`: the SDK returns a promise of the version, `version()` returns the int. In a workflow
class, `Workflow::DEFAULT_VERSION` becomes `ChangePoint::DEFAULT_VERSION`, with its `use` import:
both are `-1`, so a comparison with it keeps its meaning. A reference outside a workflow class is
marked. A `getVersion()` call with another number of arguments, or one that is not yielded where it
is made, is left as written and marked.

One runtime case differs. A run that went past the point before the call existed and has no
recorded work after it, for example one waiting only on a condition or a signal, gets
`$maxSupported` from `version()`, where the SDK returns `DEFAULT_VERSION`. The docblock of
`ExecutionContext::version()` describes this limit.

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

It works from an **allow-list**: eight facade methods are recognised as ones the execution-model
half will rewrite (`newActivityStub`, `newChildWorkflowStub`, `await`, `awaitWithTimeout`, `timer`,
`sideEffect`, `continueAsNew`, `getVersion`), and **everything else is reported**. `Workflow::` carries some forty
static methods and `WorkflowEnvironment` answers eight; a deny-list would pass in silence every one
nobody enumerated, the next SDK release included.

It also reports the **options objects** — `ActivityOptions`, `RetryOptions`,
`ChildWorkflowOptions`, `ContinueAsNewOptions`, `LocalActivityOptions` — in the same pass that
rewrites the call around them. `ActivityOptions::new()->withStartToCloseTimeout(…)` has no
counterpart in `ActivityOptions::of()` over `ActivityTimeouts` and `RetryLimit`; rewritten silently,
the result would read as migrated and could not run.

It marks two more kinds of statement, for the same reason:

- a reference to `ApplicationFailure`, `ServerFailure`, `TerminatedFailure` or `TimeoutFailure`
  (in a `catch`, a `new`, a `throw`, an `instanceof`, a static call, a `::class`, a parameter type,
  a return type, or the `extends` of a named class). Durable has no counterpart for these four, and
  once `temporal/sdk` is removed the reference no longer resolves. A `catch` is marked above its
  `try`, a parameter or return type above its method or function, an `extends` above its class; the
  `use` import is not marked.
- a `Promise::` call that the execution-model half does not rewrite: any method other than `all`,
  `any` and `some`, any of those three called with no argument, and `some()` called without a count.

A statement or a method can carry several markers, one per finding. A re-run adds a marker only
for a finding not marked yet. Two markers count as the same finding when their text matches up to
the first ` — `, so a failure marker written by an earlier run with the older explanation ("a catch
on it never matches after migration") gets no second one.

Run against [`temporalio/samples-php`](https://github.com/temporalio/samples-php), the whole set
changes **58 files** — and it reports
coroutines (`async`, `asyncDetached`), the mutex (`runLocked`, `Mutex`), run introspection
(`getInfo`, `getCurrentContext`, `isReplaying`), the saga helper, activity-by-name, in-run search
attributes, and the options objects.

## What it does not do

**Write a return type.** The `\Generator` goes; nothing replaces it. Declaring what a migrated
method returns is yours, and the contract's docblock is usually where it is written down.

**Migrate the options objects or a Saga.** It reports them. See
[OST004 §6](https://github.com/gplanchat/durable-dev/blob/main/documentation/ost/OST004-what-is-not-built-yet.md).

**Anything with no counterpart** — it reports those rather than pretending.
`Workflow::newUntypedActivityStub()` and activity-by-name calls were removed on purpose
([DUR039](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR039-workflow-authoring-surface.md)).

### What it leaves unchanged without a marker

These constructs carry no `durable-rector:` comment after a run. Check them by hand:

- a `callable` that is not a `\Closure` passed to `Workflow::sideEffect()`: it is rewritten, and
  throws a `TypeError` on first run;
- a plain iterator generator inside a workflow class: its `yield` is rewritten like any other;
- a `\Generator` return type: it is removed, and nothing records that it was there;
- the `Temporal\Activity` facade, called from activity code (`Activity::getInfo()`,
  `Activity::heartbeat()`);
- the client side: code that starts, signals or queries a workflow through the SDK client;
- the SDK workflow attribute on a class rather than on an interface: no `#[AsWorkflow]` is
  written, because both rules read that attribute only on the interfaces a class implements;
- an interceptor: no rule matches it;
- an anonymous class that extends `ApplicationFailure`, `ServerFailure`, `TerminatedFailure` or
  `TimeoutFailure`, such as `throw new class extends ApplicationFailure {}`.

## Development

`temporal/sdk` is **not** a dependency, of this package or of the monorepo — see
[DUR006](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md). Rector
matches attributes by fully-qualified name and never loads them, so the tests declare the shape they
read in `tests/unit/DurableRector/Source/temporal-sdk-stubs.php`, under the SDK's own namespace.

```bash
vendor/bin/phpunit --testsuite unit --filter DurableRector
```
