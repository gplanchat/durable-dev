# Index — Durable documentation

This repository documents the **Durable** component (durable execution orchestrated with Temporal, without the official PHP SDK or RoadRunner — see [DUR006](adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md)). Symfony Messenger integration is covered in **DUR021**. **Commands-only** orchestration is **DUR026**; the **gRPC bridge** is **DUR019**; the **fiber-based interpreter** (`WorkflowTaskRunner`) is **DUR027**; the **fiber and replay model** is **DUR003**. **[DUR025](adr/DUR025-temporal-grpc-workflowservice-messages-and-implementation-map.md)** maps **WorkflowService** gRPC RPCs to this codebase.

**Language:** All normative documents in `documentation/adr/`, `documentation/wa/`, tracking, and Cursor rules are **English** — see [WA001](wa/WA001-english-language-documentation.md). **Development** follows **TDD** (Red → Green → Refactor) — see [WA002](wa/WA002-test-driven-development.md). **GitHub** epics, tasks, stories, and project usage follow **[WA003](wa/WA003-github-epics-tasks-and-project-tracking.md)**. **Licensing:** the repository and first-party Composer packages are **MIT** — see [WA004](wa/WA004-mit-license-distribution.md) and the root [`LICENSE`](../LICENSE) file.

> **Attribute names in the records below predate v0.1.0-alpha8.** Every declaration attribute
> gained the `As` prefix in that version — `#[Workflow]` became `#[AsWorkflow]`, `#[ActivityMethod]`
> became `#[AsActivityMethod]`, and `#[AsDurableActivity]` left the Symfony bundle for the core as
> `#[AsActivityHandler]`. The records are **not** rewritten: an ADR states what was decided when it
> was written, and editing the code samples inside one would falsify that. The mapping, and the
> Rector set that applies it, are in [`UPGRADE.md`](../UPGRADE.md). The user documentation, which
> teaches current usage rather than recording a decision, does use the new names.

## Architecture Decision Records (ADR)

| ID | Title | File |
|----|--------|------|
| DUR000 | ADR management process | [adr/DUR000-adr-management-process.md](adr/DUR000-adr-management-process.md) |
| DUR001 | Event store and cursor traversal | [adr/DUR001-event-store-and-cursor.md](adr/DUR001-event-store-and-cursor.md) |
| DUR002 | WorkflowClient, WorkflowHistorySourceInterface, WorkflowCommandBufferInterface | [adr/DUR002-cqrs-temporal-repositories.md](adr/DUR002-cqrs-temporal-repositories.md) |
| DUR003 | Fiber-based replay, ExecutionEngine, and awaitables | [adr/DUR003-workflow-state-machine-replay-and-awaitables.md](adr/DUR003-workflow-state-machine-replay-and-awaitables.md) |
| DUR004 | ActivityInvoker, activities, and activity methods | [adr/DUR004-activity-stub-and-activities.md](adr/DUR004-activity-stub-and-activities.md) |
| DUR005 | Temporal and In-Memory backends | [adr/DUR005-implementation-backends-temporal-and-in-memory.md](adr/DUR005-implementation-backends-temporal-and-in-memory.md) |
| DUR006 | No official Temporal PHP SDK or RoadRunner | [adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md](adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md) |
| DUR007 | Serialization and Symfony Serializer | [adr/DUR007-serialization-and-symfony-serializer.md](adr/DUR007-serialization-and-symfony-serializer.md) |
| DUR008 | PER (PHP-FIG) style and naming | [adr/DUR008-per-php-fig-naming-and-style.md](adr/DUR008-per-php-fig-naming-and-style.md) |
| DUR009 | Testing standards | [adr/DUR009-testing-standards.md](adr/DUR009-testing-standards.md) |
| DUR010 | Test pyramid | [adr/DUR010-test-pyramid.md](adr/DUR010-test-pyramid.md) |
| DUR011 | Errors, classification, and retries | [adr/DUR011-errors-retries-and-classification.md](adr/DUR011-errors-retries-and-classification.md) |
| DUR012 | API client layer and repository adapters | [adr/DUR012-api-client-and-repository-adapter-layers.md](adr/DUR012-api-client-and-repository-adapter-layers.md) |
| DUR013 | Workflow modeling and Query / Signal / Update surface | [adr/DUR013-workflow-modeling-and-temporal-surface.md](adr/DUR013-workflow-modeling-and-temporal-surface.md) |
| DUR014 | Temporal edge cases and external integrations | [adr/DUR014-temporal-edge-cases-and-integrations.md](adr/DUR014-temporal-edge-cases-and-integrations.md) |
| DUR015 | Repository and adapter testing | [adr/DUR015-repository-and-adapter-testing.md](adr/DUR015-repository-and-adapter-testing.md) |
| DUR016 | In-Memory backend: rules and exceptions | [adr/DUR016-in-memory-backend-exception-rules.md](adr/DUR016-in-memory-backend-exception-rules.md) |
| DUR017 | Observability and operations | [adr/DUR017-observability-and-operations.md](adr/DUR017-observability-and-operations.md) |
| DUR018 | Event parity, slots, and replay (Temporal alignment) | [adr/DUR018-temporal-event-parity-replay-and-slots.md](adr/DUR018-temporal-event-parity-replay-and-slots.md) |
| DUR019 | Temporal gRPC bridge | [adr/DUR019-temporal-grpc-bridge-and-journal.md](adr/DUR019-temporal-grpc-bridge-and-journal.md) |
| DUR020 | Monorepo, splitsh, and satellite repositories | [adr/DUR020-monorepo-splitsh-and-satellite-repositories.md](adr/DUR020-monorepo-splitsh-and-satellite-repositories.md) |
| DUR021 | Symfony Messenger integration | [adr/DUR021-symfony-messenger-integration.md](adr/DUR021-symfony-messenger-integration.md) |
| DUR022 | Workflow class, interface, and WorkflowEnvironment | [adr/DUR022-workflow-class-interface-and-workflow-environment.md](adr/DUR022-workflow-class-interface-and-workflow-environment.md) |
| DUR023 | Activity authoring and asynchronous activity invoker | [adr/DUR023-activity-authoring-and-asynchronous-activity-proxy.md](adr/DUR023-activity-authoring-and-asynchronous-activity-proxy.md) |
| DUR024 | Temporal native execution: WorkflowTaskRunner and fiber-based interpreter | [adr/DUR024-temporal-native-execution-and-interpreter.md](adr/DUR024-temporal-native-execution-and-interpreter.md) |
| DUR025 | Temporal WorkflowService gRPC RPCs: implementation map | [adr/DUR025-temporal-grpc-workflowservice-messages-and-implementation-map.md](adr/DUR025-temporal-grpc-workflowservice-messages-and-implementation-map.md) |
| DUR026 | Commands-only orchestration path | [adr/DUR026-spike-first-temporal-orchestration.md](adr/DUR026-spike-first-temporal-orchestration.md) |
| DUR027 | WorkflowTaskRunner: fiber-based replay from Temporal history | [adr/DUR027-workflow-task-runner-fiber-replay.md](adr/DUR027-workflow-task-runner-fiber-replay.md) |
| DUR028 | Synchronous completion polling for multi-process Temporal setups | [adr/DUR028-synchronous-completion-polling-multi-process.md](adr/DUR028-synchronous-completion-polling-multi-process.md) |
| DUR029 | Temporal read-through event store and profiler event conversion | [adr/DUR029-temporal-profiler-read-through-event-store.md](adr/DUR029-temporal-profiler-read-through-event-store.md) |
| DUR030 | DBAL backend: simplified durable execution on a single SQL database | [adr/DUR030-dbal-backend-simplified-durable-execution.md](adr/DUR030-dbal-backend-simplified-durable-execution.md) |
| DUR031 | Value objects across the ports, and who owns the wire | [adr/DUR031-value-objects-across-ports-and-wire-ownership.md](adr/DUR031-value-objects-across-ports-and-wire-ownership.md) |
| DUR032 | Workflow-side deadlines: a failure, and a verdict read from history | [adr/DUR032-workflow-side-deadlines.md](adr/DUR032-workflow-side-deadlines.md) |
| DUR033 | Assemblers return an Awaitable, and `await()` is the only wait | [adr/DUR033-awaitable-assemblers-and-the-single-wait.md](adr/DUR033-awaitable-assemblers-and-the-single-wait.md) |
| DUR034 | A signal name is a backed enum, and the wire keeps the string | [adr/DUR034-signal-names-as-backed-enums.md](adr/DUR034-signal-names-as-backed-enums.md) |
| DUR035 | The condition is the primitive, and handlers are dispatched by the engine | [adr/DUR035-conditions-are-the-primitive-and-handlers-are-dispatched.md](adr/DUR035-conditions-are-the-primitive-and-handlers-are-dispatched.md) |
| DUR036 | Nexus and the backend asymmetry (caller-only framing superseded by DUR045) | [adr/DUR036-nexus-caller-only-and-the-backend-asymmetry.md](adr/DUR036-nexus-caller-only-and-the-backend-asymmetry.md) |
| DUR037 | Run observation is a projection, and an absent fact stays absent | [adr/DUR037-run-observation-as-a-projection.md](adr/DUR037-run-observation-as-a-projection.md) |
| DUR038 | A stub assembles, it does not wait | [adr/DUR038-a-stub-assembles-it-does-not-wait.md](adr/DUR038-a-stub-assembles-it-does-not-wait.md) |
| DUR039 | The workflow authoring surface | [adr/DUR039-workflow-authoring-surface.md](adr/DUR039-workflow-authoring-surface.md) |
| DUR040 | Query plumbing leaves the environment | [adr/DUR040-query-plumbing-leaves-the-environment.md](adr/DUR040-query-plumbing-leaves-the-environment.md) |
| DUR041 | Store parity is a suite every adapter runs | [adr/DUR041-store-parity-is-a-suite-every-adapter-runs.md](adr/DUR041-store-parity-is-a-suite-every-adapter-runs.md) |
| DUR042 | The replay divergence guard | [adr/DUR042-replay-divergence-guard.md](adr/DUR042-replay-divergence-guard.md) |
| DUR043 | The projection is a port, and the in-memory backend reads its own runs | [adr/DUR043-the-projection-is-a-port-and-in-memory-reads-itself.md](adr/DUR043-the-projection-is-a-port-and-in-memory-reads-itself.md) |
| DUR044 | Declared change points | [adr/DUR044-declared-change-points.md](adr/DUR044-declared-change-points.md) |
| DUR045 | Serving a Nexus operation: one worker, two shapes, and a refusal at startup | [adr/DUR045-serving-a-nexus-operation.md](adr/DUR045-serving-a-nexus-operation.md) |
| DUR046 | Magento: a Tier 1 host, and the four things it changed about the core | [adr/DUR046-magento-a-tier-1-host-that-improved-the-core.md](adr/DUR046-magento-a-tier-1-host-that-improved-the-core.md) |

## Working agreements (WA)

| ID | Title | File |
|----|--------|------|
| WA001 | English language for project documentation | [wa/WA001-english-language-documentation.md](wa/WA001-english-language-documentation.md) |
| WA002 | Test-driven development (TDD) | [wa/WA002-test-driven-development.md](wa/WA002-test-driven-development.md) |
| WA003 | GitHub epics, tasks, and project tracking | [wa/WA003-github-epics-tasks-and-project-tracking.md](wa/WA003-github-epics-tasks-and-project-tracking.md) |
| WA004 | MIT license for the repository and Composer packages | [wa/WA004-mit-license-distribution.md](wa/WA004-mit-license-distribution.md) |
| WA005 | The canvas is the source, `layouts/index.html` is output | [wa/WA005-the-canvas-is-the-source-the-page-is-output.md](wa/WA005-the-canvas-is-the-source-the-page-is-output.md) |

## Opportunity solution trees (OST)

| ID | Title | File |
|----|--------|------|
| OST001 | Alternative durable execution backends (market study) | [ost/OST001-alternative-durable-execution-backends.md](ost/OST001-alternative-durable-execution-backends.md) |
| OST002 | Durable Task / Dapr as a Durable backend: feasibility (contraindicated) | [ost/OST002-durable-task-backend-feasibility.md](ost/OST002-durable-task-backend-feasibility.md) |
| OST003 | PHP ecosystem integrations: where Durable is worth wiring | [ost/OST003-php-ecosystem-integrations.md](ost/OST003-php-ecosystem-integrations.md) |
| OST004 | What is not built yet: cost, and what blocks it | [ost/OST004-what-is-not-built-yet.md](ost/OST004-what-is-not-built-yet.md) |

## Other

- [Work journal](journal/README.md)
- [Document lifecycle](LIFECYCLE.md)
- [Hugo user guide](HUGO.md) (built from `documentation/user/` only; ADRs/WAs are not mirrored)
- [User documentation source (Markdown)](user/) — content published by Hugo as the end-user site
  - [Getting started](user/getting-started/) — installation, Symfony bundle config, first workflow
  - [Backends](user/backends/) — In-Memory vs Temporal: Docker Compose setup, DSN format
  - [Concepts](user/concepts/) — workflows, activities, replay, backends
  - [Creating a workflow](user/workflows/) — `WorkflowEnvironment`, attributes, signals, queries, updates
  - [Creating activities](user/activities/) — `ActivityMethod`, `ActivityOptions`, DI, serialization
  - [Testing workflows](user/testing/) — `DurableTestCase`, `ActivitySpy`, `WorkflowTestEnvironment`, `DurableBundleTestTrait`
  - [Configuration reference](user/configuration/) — every `durable.yaml` key explained
