# DUR009 — Testing standards

## Status

Amended (DUR009-v2) — added conventions for `FakeWorkflowServiceClient`, `ActivitySpy`, `WorkflowTestEnvironment`, `DurableBundleTestTrait` (2026-04-04)

## Context

The Durable component relies on **deterministic behaviour** (replay, workflow idempotence) and **adapters** (Temporal, In-Memory). Tests must **protect** these contracts without fragility, without depending on randomness or real time, and without opaque test doubles.

## Decision

### Framework and tooling

- **PHPUnit** is the default test framework for project PHP code.
- Tests **must** be **deterministic**: no reliance on real time, uncontrolled random IDs, or unsimulated external networks.

### Organisation

- **One test case** (method) **one intent**: readable test name describing expected behaviour.
- **Data**: prefer explicit **fixtures** or **builders** over repeated magic literals; shared factors in dedicated test data methods or classes when it improves clarity.

### Doubles and isolation

- **Test doubles** (fakes, stubs, spies) are **preferred** over generic mocks when readability and behaviour control are better.
- **Mocks** are **not** forbidden, but use should stay **limited** to boundaries where injecting behaviour is simplest.
- **Domain** and **port** tests must **not** depend on a real Temporal cluster: use the **In-Memory** backend (DUR005) or dedicated doubles.

#### Doubles shipped with the component

| Class | Package | Usage |
|---|---|---|
| `Gplanchat\Durable\Testing\ActivitySpy` | `gplanchat/durable` | Controllable fake for an activity. Drive return values, force exceptions, assert calls. |
| `Gplanchat\Durable\Testing\WorkflowTestEnvironment` | `gplanchat/durable` | Facade wiring in-memory pieces (`InMemoryEventStore`, `InMemoryWorkflowMetadataStore`, `ExecutionEngine`, …) for workflow unit tests. |
| `Gplanchat\Durable\Testing\DurableTestCase` | `gplanchat/durable` | Preconfigured PHPUnit base. Instantiates `WorkflowTestEnvironment` and exposes helpers (`runWorkflow()`, `assertWorkflowResult()`, …). |
| `Gplanchat\Bridge\Temporal\Testing\FakeWorkflowServiceClient` | `gplanchat/durable-bridge-temporal` | In-process Temporal gRPC service implementation. Used to test the Temporal bridge without a real cluster. |
| `Gplanchat\Durable\Bundle\Testing\DurableBundleTestTrait` | `gplanchat/durable-bundle` | PHPUnit trait for `KernelTestCase`. Exposes `dispatchWorkflow()`, `drainMessengerUntilSettled()`, `assertWorkflowResultEquals()`, `getEventStoreService()`. |

### Temporal and workflows

- **No official Temporal SDK** in tests either (DUR006): tests validate the component’s **abstractions**, not a forbidden third-party client.
- **Replay** and **idempotence** scenarios (DUR003) are covered by **repeatable** tests (same input → same history / same simulated decision).
- **`FakeWorkflowServiceClient`** is the reference double for Temporal bridge tests: it simulates the gRPC API in memory without a cluster. PHPUnit bridge tests **must** use it instead of a real gRPC client.
- Any public `FakeWorkflowServiceClient` method not yet implemented throws `\BadMethodCallException` to signal unsupported usage clearly.

### Style and conventions

- Test code follows **PER** (DUR008): test class names, methods, and file layout match project conventions.

### Out of scope for this ADR

- The **proportion** of unit vs integration vs end-to-end tests is defined in **DUR010** (test pyramid).

## Consequences

- CI **should** run the PHPUnit suite on relevant changes and enforce minimum coverage or quality bars set by maintainers.
- META documents may detail patterns (fixtures, builders) without duplicating the principles above.
- `DurableTestCase`, `ActivitySpy`, and `WorkflowTestEnvironment` ship under `src/Durable/Testing/` and are available **without** the Symfony bundle — suitable for framework-free integration too.
- `DurableBundleTestTrait` ships under `src/DurableBundle/Testing/` and requires `symfony/framework-bundle` and `symfony/messenger`.
