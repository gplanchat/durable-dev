# gplanchat/durable-rector

Rector rules that move a project off the official **Temporal PHP SDK** onto
[`gplanchat/durable`](https://github.com/gplanchat/durable-dev) — and, above all, keep the
**workflow and activity type names a running server already knows**.

```bash
composer require --dev gplanchat/durable-rector
```

```php
// rector.php
return Rector\Config\RectorConfig::configure()
    ->withImportNames()   // or the rewritten names land fully qualified, next to a stale `use`
    ->withSets([__DIR__ . '/vendor/gplanchat/durable-rector/config/sets/temporal-sdk.php']);
```

## What it does

| Rule | What moves |
|---|---|
| `ActivityContractAttributesRector` | `#[ActivityInterface(prefix:)]` → `#[Activity(name:)]`, and every public method gets an explicit `#[ActivityMethod(name:)]` |
| `WorkflowClassAttributesRector` | `#[WorkflowInterface]` and the four method attributes are **copied from the interface onto the implementing class**, where Durable reads them |
| `RenameClassRector` (configured) | The three SDK failures with a Durable counterpart |
| `UnmigratableTemporalCallRector` | Comments every call the migration **cannot** make, and changes nothing else |

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

Run against [`temporalio/samples-php`](https://github.com/temporalio/samples-php), it reports 23
findings across 10 files — coroutines (`async`, `asyncDetached`), the mutex
(`runLocked`, `Mutex`), run introspection (`getInfo`, `getCurrentContext`, `isReplaying`), the saga
helper, activity-by-name, and in-run search attributes.

## What it does not do

**The execution model.** After this set has run, `yield` is still `yield` and `Workflow::` is still a
static call. Those two need what a rename cannot supply: a receiver the source class does not have
(`WorkflowEnvironment`, injected in the constructor) and a return type the SDK could not declare
(the method returned a `Generator`). See
[OST004 §6](../../documentation/ost/OST004-what-is-not-built-yet.md) for the shape of that work.

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
