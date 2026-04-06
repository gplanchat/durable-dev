# Index — Durable documentation

This repository documents the **Durable** component (durable execution orchestrated with Temporal, without the official PHP SDK or RoadRunner — see [DUR006](adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md)). Symfony Messenger integration is covered in **DUR021**. **Commands-only** orchestration is **DUR026**; the **gRPC bridge** is **DUR019**; the **fiber-based interpreter** (`WorkflowTaskRunner`) is **DUR027**; the **fiber and replay model** is **DUR003**. **[DUR025](adr/DUR025-temporal-grpc-workflowservice-messages-and-implementation-map.md)** maps **WorkflowService** gRPC RPCs to this codebase.

**Language:** All normative documents in `documentation/adr/`, `documentation/wa/`, tracking, and Cursor rules are **English** — see [WA001](wa/WA001-english-language-documentation.md). **Development** follows **TDD** (Red → Green → Refactor) — see [WA002](wa/WA002-test-driven-development.md). **GitHub** epics, tasks, stories, and project usage follow **[WA003](wa/WA003-github-epics-tasks-and-project-tracking.md)**.

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

## Working agreements (WA)

| ID | Title | File |
|----|--------|------|
| WA001 | English language for project documentation | [wa/WA001-english-language-documentation.md](wa/WA001-english-language-documentation.md) |
| WA002 | Test-driven development (TDD) | [wa/WA002-test-driven-development.md](wa/WA002-test-driven-development.md) |
| WA003 | GitHub epics, tasks, and project tracking | [wa/WA003-github-epics-tasks-and-project-tracking.md](wa/WA003-github-epics-tasks-and-project-tracking.md) |

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
