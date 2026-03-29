# OST003 — Activity call ergonomics (class / attribute style)

**Canonical document**: This English OST003 is the full reference for activity / workflow DX in this repository. Shorter summaries or any legacy non-English draft are **superseded** by this file; extend here if new tracks or decisions are needed.

**Architecture lock-in**: runtime metadata cache, warmup, `activityStub` PHPDoc alignment, and static-analysis packaging are recorded in **[ADR012](../adr/ADR012-activity-stub-metadata-and-static-analysis.md)**.

## Context

The current API:

```php
$ctx->activity('greet', ['name' => 'Durable']);
```

is correct for the engine (name + JSON-serializable array in the event store) but weak for workflow authors: no contract typing, no IDE completion, “magic” string names.

The goal is to follow the **Temporal** spirit (explicit contracts, dedicated classes, declarative metadata) while staying compatible with **PHP 8.2+**, the existing **event log**, and **transports**.

### Decision: `activity()` lives in the **engine**

**Scheduling** an activity (building the `Awaitable`, writing to the log / event store, serializable name + payload) must be implemented **once** in the **engine layer** (dedicated service, `ExecutionEngine`, internal adapter — exact name to fix in an ADR), not duplicated in user workflow code.

- **`ExecutionContext::activity(string, array)`** (as exposed today) becomes at least a **façade** to that core, or is **reduced** to a low-level / internal entry point per migration plan.
- All **stub** ergonomics (`activityStub` / `ActivityStub<T>`), **DTO** (`invoke`), etc. **delegate** to the **same** engine implementation — no parallel path.

## Constraints to preserve

- **Persisted payload** stays a **JSON array** (or strictly serializable structure) in `ActivityScheduled` / `ActivityMessage`.
- **Logical activity name** stays a **stable string** over time (versioning = another name or `schemaVersion` field in a DTO).
- **Backward compatibility**: existing workflows may keep calling `$ctx->activity(...)` as long as it **redirects** to the engine; the **target DX** is **`activityStub(Contract::class)`** (one line) then typed method calls — **no** hand-written wrapper class.

### **`#[Workflow]`** and **`#[Activity]`** (class) — **stable names if the PHP class is renamed**

The **logical name** stored in the log / used by the worker must **not** depend only on the **PHP class name**: a refactor (`OrderWorkflow` → `ProcessOrderWorkflow`, `OrderActivitiesInterface` → `CommerceActivitiesInterface`) must not **break** workflow or activity contract identity in history.

**To specify** (ADR: target `Attribute::TARGET_CLASS`, possibly `TARGET_INTERFACE`):

| Attribute | Scope | Role |
|-----------|-------|------|
| **`#[Workflow('logical_name')]`** | Class (or interface) of the **workflow handler** | **Stable** workflow type id for engine / event store, **independent** of FQCN after rename. |
| **`#[Activity('logical_name')]`** | Activity **contract** (interface or worker impl class), **or** call DTO (track A) | **Stable** id for **grouping** or **activity type** per chosen model; complements **`#[ActivityMethod]`** on methods (track C). |

**Track C**: put **`#[Activity('…')]`** on **`OrderActivitiesInterface`** (and ideally the same value on the concrete worker class); **`#[ActivityMethod('reserve_stock')]`** remain **elementary** activity names. The ADR defines how the engine **composes** `Activity` + `ActivityMethod` (prefix `logical.`, method, PSR-6 cache keys, registry keys, etc.).

**Track A**: **`#[Activity('greet')]`** on a **readonly DTO** already fixes the **activity name** for the call; renaming class `GreetInput` without changing **`'greet'`** preserves history compatibility.

**Tooling**: warmers, **PHPStan / Psalm** plugins, and consistency checks must read these attributes at **class** level as well as methods.

---

## Track A — `#[Activity]` attribute + input DTO (recommended alternative)

A **readonly class** represents the call; a PHP 8 attribute carries the **activity name** for the worker (see *stable names* above).

```php
#[Activity('greet')]
final readonly class GreetInput
{
    public function __construct(public string $name) {}
}
```

Same **`Activity`** attribute as on the activity **contract** (track C), here on a **DTO** class: the **`'greet'`** string is the **stable** log id; renaming `GreetInput` does not break history as long as **`'greet'`** is unchanged.

Target workflow API:

```php
await($ctx->invoke(new GreetInput('Durable')), $ctx, $rt);
// or readable alias:
await($ctx->runActivity(new GreetInput('Durable')), $ctx, $rt);
```

Conceptual implementation:

1. `ActivityInvocation` interface (or trait): `activityName(): string`, `toActivityPayload(): array`.
2. **`#[Activity('greet')]`**: metadata resolved **off hot path** — same as track C: **PSR-6 cache** filled at **warmup**; in dev a **miss** may trigger one-off resolution then pool write.
3. `ExecutionContext::invoke(object $input): Awaitable`: resolve name from attribute on `$input`’s class; call `toActivityPayload()` (default: `get_object_vars` / dedicated normalizer); **delegate to engine** (same primitive as internal `activityStub` / `activity()`).

**Implicit name variant**: if attribute omits name, derive `greet` from `GreetInput` → `greet` (camelCase / snake_case documented).

**Pros**: Temporal-like, strong typing, one class = one contract. **Cons**: need **warmup / cache** to avoid reflection at runtime; serialization convention to fix (see ADR006).

---

## Track B — Typed enum for name + separate payload

To minimize reflection:

```php
enum ActivityName: string
{
    case Greet = 'greet';
    case ChargeCard = 'charge_card';
}

await($ctx->activityNamed(ActivityName::Greet, new GreetPayload('Durable')), ...);
```

`GreetPayload` stays a readonly DTO with explicit `toArray()`.

**Pros**: exhaustive activity names for `switch` / PHPStan. **Cons**: two artifacts (enum + DTO) per activity when payload is non-empty.

---

## Track C — Activity contract + engine **`activityStub()`** — **CHOSEN**

### Target workflow syntax (simple, no user-written proxy)

The user **does not** create an “Activities” class or methods wrapping `activity()`. They declare only the **contract** (interface + worker implementation) with **`#[Activity('…')]`** on the type (stable name) and **`#[ActivityMethod]`** on business methods; the engine provides the proxy in **one** expression.

```php
/** @var ActivityStub<OrderActivitiesInterface> $orders */
$orders = $env->activityStub(OrderActivitiesInterface::class);

$reserved = $env->await($orders->reserveStock('SKU-1', 3));
```

**User writes**: activity interface, concrete worker class, then **`activityStub`** + method calls. **User does not write**: intermediate classes duplicating activity names or calling `$env->activity(string)` by hand.

### Single concrete class + interface (contract only)

There are **not** two concrete implementations of the same activity: **one class** holds business code and runs on the worker.

| Piece | Role |
|-------|------|
| **Interface** (e.g. `OrderActivitiesInterface`) | **Aligned** with worker class: shared signatures; concrete class **`implements`** interface. **`#[Activity('stable_name')]`** on type (interface + impl): **stable** id if PHP symbol is renamed. Only **`#[ActivityMethod]`** methods are schedulable via stub; other methods: not exposed to workflow. |
| **Engine proxy** (`ActivityStub<…>`) | From **`$env->activityStub(...)`**: only place workflow sees **`Awaitable<R>`**. Only **`#[ActivityMethod]`** methods are exposed: same names / parameters as contract, returns become suspended `Awaitable<R>`. |

On the **workflow** side (replay), the proxy **emits** `Awaitable` to the log — no business body execution. On the **worker**, **concrete class** methods run.

### Role of `#[Activity]` (class) and `#[ActivityMethod]` (method)

In **Temporal** (Java, .NET, official PHP SDK), workflows call **generated or annotated stubs**; server-side activity name is **contract data**, not scattered strings.

**`#[Activity('…')]`** on the **contract**: **stable** anchor for engine, PSR-6 cache, and registry when **class or interface name** changes. Must be **consistent** between interface and worker impl (check at warmup or test).

**`#[ActivityMethod]`** on each contract method that is an activity:

1. **Bound stub surface**: proxy from `activityStub()` exposes **only** these methods.
2. **Elementary logical activity name** in worker registry — **source of truth** for static analysis.
3. **Verification**: tooling walks **annotated** methods and checks each `#[ActivityMethod('…')]` has a registered handler.
4. **Drift control**: renaming PHP method `greetCustomer()` does not change history id; only attribute parameter stays stable.
5. **Evolution**: optional attribute fields for future metadata (task queue, retry policy, timeouts).

**Envisaged shape** (ADR — attribute classes in dedicated namespace e.g. `…\Attribute\`):

```php
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_INTERFACE)]
final class Activity
{
    public function __construct(public string $name) {}
}

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_INTERFACE)]
final class Workflow
{
    public function __construct(public string $name) {}
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class ActivityMethod
{
    public function __construct(public string $name) {}
}
```

**Example** — interface **aligned** with activity; **`Awaitable` only via `activityStub()`** (not in interface); **stable names** on PHP rename:

```php
#[Activity('order_activities')]
interface OrderActivitiesInterface
{
    #[ActivityMethod('reserve_stock')]
    public function reserveStock(string $sku, int $quantity): string;
}

#[Activity('order_activities')]
final class AcmeOrderActivities implements OrderActivitiesInterface
{
    #[ActivityMethod('reserve_stock')]
    public function reserveStock(string $sku, int $quantity): string
    {
        // business code, worker only
    }
}
```

```php
#[Workflow('process_order')]
final class ProcessOrderWorkflow
{
    // …
}
```

*A method on the same contract **without** `#[ActivityMethod]` may exist for the worker: it **does not** appear on `ActivityStub<…>`.*

### Mental model: `ActivityStub<TActivity>` and `Awaitable<R>`

The **stub** is parameterized by activity class or interface (`TActivity`). Its **static surface** covers **only** `TActivity` methods with **`#[ActivityMethod]`**; for each, **same parameters** as contract, return type **`R`** becomes **`Awaitable<R>`**.

PHP cannot express this fully in the type system. **Plan**: dedicated **PHPStan and Psalm** plugins (below); optional **codegen** or manual `@var`.

```php
/**
 * Scheduling proxy on workflow side.
 * @template TActivity of object
 */
final class ActivityStub
{
    public function __construct(
        private readonly string $activityClass,
        private readonly ExecutionContext $context,
    ) {
    }
    // Runtime: __call reads metadata from PSR-6 cache (no reflection).
}
```

### Metadata cache: **PSR-6** and **warmup** only

**Decision**: **reflection** (`ReflectionClass` / `ReflectionMethod`, reading attributes) must **feed a cache**, **ideally only during warmup** (build, `cache:warmup`, Symfony compile), **not** on the workflow hot path in production.

- **Storage**: **`Psr\Cache\CacheItemPoolInterface`**. Symfony pools (`framework.cache`): natural fit with `cache:warmup` and **`CacheWarmerInterface`**.
- **Typical entry** (per activity contract `class-string`, or `(class, method)`): resolved **`#[Activity]`**, list of **`#[ActivityMethod]`** methods, logical name per method, **parameter names** (→ payload keys), optional return type for static analysis.
- **Runtime** (`ActivityStub`, `invoke(DTO)`, etc.): **read-only** from pool; **miss** in prod → policy in ADR (hard fail vs mandatory deploy warmup).

**Implementations**: codegen (optional), **PHPStan + Psalm** plugins (planned product deliverables), dev fallback (one-off reflection + `save()` — not production target).

### Static analysis: **PHPStan and Psalm plugins** — **planned**

Because **`ActivityStub`** uses **`__call`**, analyzers need extensions.

#### **Packagist**: **independent** packages and **`suggest`**

- **Two Composer packages** on **Packagist**, **not** in core **`require`**: users who do not need analyzers do not install them.
- **Opt-in**: `composer require --dev gplanchat/durable-phpstan` and/or `composer require --dev gplanchat/durable-psalm-plugin`.
- Core **`composer.json`** lists both under **`"suggest"`** with a short description.
- Each plugin **`require`**s a **minimum** compatible **`gplanchat/durable`** version; core does not depend on plugins.

**Target behaviors** (ADR / plugin specs):

1. **`activityStub(Class::class)`** → **`ActivityStub<TContract>`**; verify **`#[Activity]`** on contract; only **`#[ActivityMethod]`** methods exist on stub.
2. **`$stub->foo(...args)`** → return **`Awaitable<R>`** with **`R`** = business return type of **`TContract::foo`** (generics via `@template` / Psalm).
3. Method **without** `#[ActivityMethod]` → error (or strict level) if called on stub.
4. Track A (`invoke`): **`#[Activity]`** on DTO; complementary rule for `Awaitable`.
5. **`#[Workflow]`** on handler class for stable workflow type analysis.

**Truth source**: native **PHP attributes** via `Reflection*` during analysis — not workflow hot path; optional **warmup dump** shared with PSR-6 schema.

**User docs**: README sections + sample `phpstan.neon` / `psalm.xml` for Symfony / standalone.

```php
/**
 * @template TActivity of object
 * @param class-string<TActivity> $activityClass
 * @return ActivityStub<TActivity>
 */
public function activityStub(string $activityClass): ActivityStub { /* … */ }
```

**Out of scope for DX**: hand-written wrapper classes, `invokeFromCurrentMethod` in app code, or re-implementing scheduling. The **only** ergonomic path is **`activityStub()`** (plus optional **`invoke(DTO)`** track A).

### `activity()` in the engine — **retained**

**Primitive** `scheduleActivity(name, payload)` (or equivalent) lives in the **engine**; `ExecutionContext` **routes** to it. Workflow syntax is **`activityStub(TActivity::class)`** then methods (or **`invoke(DTO)`**) — **no** home-grown wrappers around `$ctx->activity(...)`.

**Operational flow**: factory `ExecutionContext::activityStub(string $activityClass): ActivityStub` returns **`ActivityStub<TActivity>`**. Each method call:

1. resolves metadata from **PSR-6** for `(TActivity, methodName)`; missing or unregistered → **reject**;
2. gets **activity name** and **parameter names** from cache (filled at **warmup**);
3. builds **payload** from arguments (convention: parameter names → associative keys);
4. returns **`Awaitable`** with business return type;
5. calls **single engine primitive**.

```php
/** @var ActivityStub<OrderActivitiesInterface> $orders */
$orders = $ctx->activityStub(OrderActivitiesInterface::class);
await($orders->reserveStock('SKU-1', 3), $ctx, $rt);
```

### Hiding `$ctx` and `$rt` on the stub

Today `await($awaitable, $ctx, $rt)` repeats context and runtime. **Options** (ADR):

1. **Bound stub**: `activityStub(..., $ctx, $rt)` or `->bindRuntime($rt)`; stub exposes `await(Awaitable $a): mixed`.
2. **Workflow façade**: `WorkflowAwait::for($ctx, $rt)`; `->await($orders->reserveStock(...))`.
3. **Bound awaitable** (optional): retain ctx+rt — must stay consistent with current `ExecutionContext` for replay.

Same flow as *Target syntax*, binding `$ctx` / `$rt` once:

```php
$w = $ctx->workflowAwait($rt);
$orders = $ctx->activityStub(OrderActivitiesInterface::class);
$sku = $w->await($orders->reserveStock('SKU-1', 3));
```

**Plan**: **PSR-6 pool** in engine / context; **Symfony warmer** walking known contracts; **interface / impl** check for same **`#[Activity]`** string; **PHPStan + Psalm**; future payload keys ≠ param names via per-param attribute or single DTO; refactor `ExecutionContext::activity()` to **adapter-only** to engine core.

**Tradeoff**: depends on **warmup** / deploy for fresh cache; **benefit**: **zero reflection** on workflow hot path, **Symfony-aligned** pools and warmers, **single scheduling location**.

### Code discipline

- Activity **interface** aligned with concrete class (`implements`); business returns — **no** `Awaitable` in interface.
- **`#[Activity('…')]`**: stable contract name.
- **`#[Workflow('…')]`**: stable workflow type, independent of PHP class rename.
- **`#[ActivityMethod]`**: explicitly marks schedulable methods on stub.
- **Stub**: only level where workflow sees **`Awaitable<R>`** for annotated methods.
- **Concrete class**: worker execution; annotated methods + registry-aligned names.
- **PHP method name** = IDE vocabulary; **`#[ActivityMethod]`** string = stable store id.
- **Cache**: PSR-6; **reflection** at **warmup** (or dev fallback), not workflow replay.
- **Static analysis**: independent **Packagist** packages; core **suggests** them.

---

## Track D — Builder / named constructors

Without attributes, immediate readability:

```php
await($ctx->activity(Greet::withName('Durable')), ...);
```

where `Greet::withName` returns a small `ActivityCall` `(name: 'greet', payload: [...])` and `activity()` accepts `ActivityCall|string`.

**Pros**: zero reflection, simple. **Cons**: less “standard Temporal”; class ↔ name link lives in factory, not only on payload type.

---

## Track E — Symfony Serializer / Normalizer

For rich payloads (nested objects, dates): `#[Activity]` on input class; serialize via **`symfony/serializer`** to `array` for the log. Document in an **ADR006** evolution if chosen.

---

## Summary

| Track | Readability | Typing | Engine effort | Temporal alignment |
|-------|-------------|--------|---------------|-------------------|
| A — `#[Activity]` + DTO | ★★★★ | ★★★★ | Medium | ★★★★★ |
| B — Enum + DTO | ★★★ | ★★★★★ | Low | ★★★ |
| C — Contract + `activityStub` | ★★★★★ | ★★★★ | Medium–high | ★★★★ |
| D — Builder / `ActivityCall` | ★★★★ | ★★★ | Low | ★★ |
| E — + Serializer | ★★★★ | ★★★★★ | High | ★★★★ |

**Current choice**: **track C** — activity contract + **`#[Activity]`** / **`#[Workflow]`** (stable names) + **`#[ActivityMethod]`** + engine-provided **`activityStub()``** (simple workflow syntax, **no** user stub); **`activity()`** implemented in the engine (single primitive).

**Next step**: dedicated ADR — attribute definitions, extract `ExecutionContext::activity()` logic into engine, implement **`activityStub()`** + **`ActivityStub<TActivity>`** (PSR-6 metadata, reflection **only in warmer**), project **only** `#[ActivityMethod]` methods, publish **`gplanchat/durable-phpstan`** and **`gplanchat/durable-psalm-plugin`**, plugin specs: `activityStub`, **`#[Activity]`**, **`#[Workflow]`**, `Awaitable<R>`, **interface** aligned with concrete class, **`workflowAwait` / bound stub**, Symfony warmers + consistency tests proxy ↔ `RegistryActivityExecutor`.

## References

- [ADR012 — Activity stub, PSR-6, warmup, static analysis](../adr/ADR012-activity-stub-metadata-and-static-analysis.md)
- [OST004 — Temporal parity](OST004-workflow-temporal-feature-parity.md)
- [ADR006 — Activity patterns](../adr/ADR006-activity-patterns.md)
- [PRD001 — Current state](../prd/PRD001-current-component-state.md)
- [ExecutionContext](../../src/Durable/ExecutionContext.php) — `activity()` façade (target: engine delegation)
- [PSR-6 — Caching](https://www.php-fig.org/psr/psr-6/)
- [PHPStan — Extensions](https://phpstan.org/developing-extensions/extension-types)
- [Psalm — Plugins](https://psalm.dev/docs/running_psalm/plugins/)
