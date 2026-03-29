# ADR012 — `activityStub`, metadata cache (PSR-6), warmup, and static analysis

## Status

Accepted

## Context

Workflow code schedules activities through **`WorkflowEnvironment::activityStub($contractClass)`**, which returns an **`ActivityStub<TActivity>`** proxy. Calls on the stub are resolved at runtime via **`ActivityContractResolver`** (reflection on **`#[Activity]`** / **`#[ActivityMethod]`**), with optional **PSR-6** caching to avoid reflection on the hot path. Symfony registers **`ActivityContractCacheWarmer`** to populate that cache during **`cache:warmup`**.

Authors expect:

- **IDE / static analysis** to know that **`$stub->reserveStock(...)`** returns **`Awaitable<R>`** where **`R`** is the contract method’s return type.
- **Stable behaviour** if the cache is cold (dev) vs warmed (prod).

OST003 describes the product direction; this ADR locks the **runtime + analysis** boundaries.

## Decision

### 1. Single scheduling primitive

- **`ExecutionContext::activity(string $name, array $payload, …)`** (or equivalent engine entry) remains the **only** primitive that writes **`ActivityScheduled`** / transport messages.
- **`ActivityStub::__call`** and any future **`invoke(DTO)`** façade **must delegate** to that primitive. No parallel scheduling path in userland.

### 2. Contract metadata: reflection + PSR-6

- **`ActivityContractResolver`** resolves **`method name → logical activity name`** from attributes, optionally prefixed by class-level **`#[Activity]`** (see `ActivityContractResolver` in the component).
- When a **`Psr\Cache\CacheItemPoolInterface`** is injected, results are stored under keys prefixed with **`durable.activity_contract.`** and a TTL (currently **3600s**). Misses trigger reflection, then **`save()`**.
- **Production expectation**: deploy or build runs **`cache:warmup`** (or equivalent) so workflow workers rarely pay reflection on first use. A cold miss is **allowed** in dev; policy for “hard fail if miss in prod” can be tightened in a follow-up ADR if needed.

### 3. Symfony warmup

- **`Gplanchat\Durable\Bundle\CacheWarmer\ActivityContractCacheWarmer`** calls **`resolveActivityMethods()`** for each configured **`class-string`** activity contract. It is **optional** (`isOptional(): true`) so environments without a full graph still boot.

### 4. PHPDoc generics alignment

- **`WorkflowEnvironment::activityStub`** MUST use the same template name as **`ActivityStub`**: **`@template TActivity`** and **`@return ActivityStub<TActivity>`**. Mismatch (e.g. **`T`** vs **`TActivity`**) breaks PHPStan’s **`ClassReflection::getPossiblyIncompleteActiveTemplateTypeMap()`** binding for third-party extensions.

### 5. Static analysis packages

- **`gplanchat/durable-phpstan`** (optional) registers **`ActivityStubMethodsClassReflectionExtension`**:
  - **`MethodsClassReflectionExtension`** exposes contract methods on **`ActivityStub`** when the **active template map** binds **`TActivity`** to the contract class.
  - Methods are accepted only if the contract declares **`#[ActivityMethod]`**, mirroring runtime **`ActivityStub`** behaviour.
  - Return type is **`Awaitable<R>`** with **`R`** inferred from the contract method’s native (and PHPDoc-resolved) signature via **`TypehintHelper`**.
- **`gplanchat/durable-psalm-plugin`** remains a **valid Composer / Psalm plugin entry point** for future work. Full **`ActivityStub`** magic-method support in Psalm requires coordinated **existence + params + return** providers with access to the **instantiated generic**; until then, **prefer PHPStan** for this project (see package README).

## Consequences

- **Positive**: One engine path; cacheable metadata; PHPStan understands **`activityStub()` → stub calls → `Awaitable<R>`** at level ≥ 1 (with **`reportMagicMethods`** as configured by PHPStan level includes).
- **Negative**: Psalm users do not yet get first-class stub inference from this monorepo; they may use suppressions, local stubs, or PHPStan on CI for durable-heavy modules.

## References

- [OST003 — Activity call ergonomics](../ost/OST003-activity-api-ergonomics.md)
- [ADR006 — Activity patterns](ADR006-activity-patterns.md)
- `src/Durable/Activity/ActivityStub.php`
- `src/Durable/Activity/ActivityContractResolver.php`
- `src/Durable/WorkflowEnvironment.php`
- `src/DurableBundle/CacheWarmer/ActivityContractCacheWarmer.php`
- `src/DurablePhpStan/` — PHPStan extension
- `src/DurablePsalmPlugin/` — plugin Psalm
