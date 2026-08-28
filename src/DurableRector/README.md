# gplanchat/durable-rector

Rector rules for projects that consume [`gplanchat/durable`](https://github.com/gplanchat/durable-dev)
and have code to migrate. Two sets, for two different migrations.

```bash
composer require --dev gplanchat/durable-rector
```

| Set | For |
|---|---|
| `temporal-sdk.php` | Coming **from the official Temporal PHP SDK**, keeping the workflow and activity type names a running server already knows |
| `durable-attributes-alpha8.php` | Already on Durable, upgrading **to v0.1.0-alpha8**, whose declaration attributes were renamed |

```php
// rector.php
return Rector\Config\RectorConfig::configure()
    ->withImportNames()   // or the rewritten names land fully qualified, next to a stale `use`
    ->withSets([__DIR__ . '/vendor/gplanchat/durable-rector/config/sets/temporal-sdk.php']);
```

---

## `durable-attributes-alpha8.php` — the attribute rename

Every declaration attribute now carries the `As` prefix. Before alpha8 the repository had two
conventions: the core named its class attributes without a prefix (`#[Workflow]`, `#[Activity]`),
while the Symfony bundle had a single prefixed one (`#[AsDurableActivity]`), and neither the
Illuminate bridge nor the Magento module had any. Serving Nexus meant adding more, so it meant
choosing. `As*` won, and it says what it says: *this is registered as an X*.

Method attributes follow the same rule, so there is one convention to remember rather than a rule
and its exception.

| before | after |
|---|---|
| `#[Workflow]` | `#[AsWorkflow]` |
| `#[Activity]` | `#[AsActivity]` |
| `#[AsDurableActivity]` *(bundle)* | `#[AsActivityHandler]` *(core)* |
| `#[WorkflowMethod]` | `#[AsWorkflowMethod]` |
| `#[ActivityMethod]` | `#[AsActivityMethod]` |
| `#[QueryMethod]` | `#[AsQueryMethod]` |
| `#[SignalMethod]` | `#[AsSignalMethod]` |
| `#[UpdateMethod]` | `#[AsUpdateMethod]` |

`AsDurableActivity` also leaves the Symfony bundle for the core. It declared an implementation,
which no framework makes specific — and leaving it bundle-side would have forced the Illuminate
bridge to invent a second attribute saying the same thing.

```php
// rector.php
return Rector\Config\RectorConfig::configure()
    ->withImportNames()
    ->withSets([__DIR__ . '/vendor/gplanchat/durable-rector/config/sets/durable-attributes-alpha8.php']);
```

**Nothing else changes.** The attributes keep their arguments, their targets and their meaning; only
the class names move. Which also means the set is safe to run twice.

**`withImportNames()` is not optional in practice.** Without it, `RenameClassRector` writes the
fully-qualified name and leaves your original `use` behind — correct code, unpleasant to read.

---

## `temporal-sdk.php` — coming off the SDK

### What it does

| Rule | What moves |
|---|---|
| `ActivityContractAttributesRector` | `#[ActivityInterface(prefix:)]` → `#[Activity(name:)]`, and every public method gets an explicit `#[ActivityMethod(name:)]` |
| `WorkflowClassAttributesRector` | `#[WorkflowInterface]` and the four method attributes are **copied from the interface onto the implementing class**, where Durable reads them |
| `RenameClassRector` (configured) | The three SDK failures with a Durable counterpart |
| `TemporalFacadeToEnvironmentRector` | The static facade becomes an injected `WorkflowEnvironment`, `yield` goes, and the `\Generator` return type with it |
| `UnmigratableTemporalCallRector` | Comments every call the migration **cannot** make, and changes nothing else |

## Monter de version à l'intérieur de Durable

Le tableau ci-dessus fait **entrer** un projet dans Durable, une fois. `durable-upgrade.php` l'y
fait **avancer**, à chaque montée de version :

```php
// rector.php
return Rector\Config\RectorConfig::configure()
    ->withImportNames()
    ->withSets([__DIR__ . '/vendor/gplanchat/durable-rector/config/sets/durable-upgrade.php']);
```

Il est cumulatif — le passer une fois rattrape toutes les versions franchies. Ce qu'il contient, et
surtout ce qu'il **ne peut pas** faire tout seul (un conteneur Symfony compilé garde les noms
pleinement qualifiés, et veut son `cache:clear`), est écrit version par version dans
[`UPGRADE.md`](../../UPGRADE.md) à la racine du dépôt.

### Why the names are the whole point

Both engines derive a type name, and **they derive it differently**:

- The SDK's activity type is `prefix . (name ?? methodName)` — one concatenation, no separator
  inserted. Durable's is `Activity::$name . '.' . ActivityMethod::$name`, and the dot is not
  optional. The two agree on exactly two prefixes: the empty one, and one ending in a dot. **On any
  other prefix this rule changes nothing** and leaves the SDK attribute in place, rather than rename
  an activity that has runs in flight.
- The SDK's workflow type is `#[WorkflowMethod(name:)]` if given, else the **interface's** short
  name. Durable's `#[Workflow]` is *optional* and falls back to the **class's** short name. A class
  migrated without an explicit name therefore compiles, passes its tests, and stops resolving every
  run already started. The rule always writes the name out — and over
  [`temporalio/samples-php`](https://github.com/temporalio/samples-php), 24 of the 27 names it
  writes are ones the fallback would have got wrong.
- The SDK treats every public method of an `#[ActivityInterface]` as an activity; Durable only an
  annotated one. Methods that carried no `#[ActivityMethod]` get one, named after themselves.

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
qualifies by implementing an `#[WorkflowInterface]` contract or by calling the facade. Inside one
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
[OST004 §6](../../documentation/ost/OST004-what-is-not-built-yet.md).

**Anything with no counterpart** — it reports those rather than pretending. `Workflow::getVersion()`
has no target at all until workflow versioning lands; `Workflow::newUntypedActivityStub()` and
activity-by-name calls were removed on purpose
([DUR039](../../documentation/adr/DUR039-workflow-authoring-surface.md)).

## Development

`temporal/sdk` is **not** a dependency, of this package or of the monorepo — see
[DUR006](../../documentation/adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md). Rector
matches attributes by fully-qualified name and never loads them, so the tests declare the shape they
read in `tests/unit/DurableRector/Source/temporal-sdk-stubs.php`, under the SDK's own namespace.

```bash
vendor/bin/phpunit --testsuite unit --filter DurableRector
```
