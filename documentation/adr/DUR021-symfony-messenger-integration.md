# DUR021 — Symfony Messenger integration

## Status

Accepted

## Context

Durable workflows in **distributed** mode must **resume** after suspension (scheduled activity, timer, signal, etc.). **Activities** often run in **workers** separate from the workflow engine. A reliable **bus**, already present in the Symfony ecosystem, is needed to carry **resume**, **activity execution**, and **wake** (timer) messages while staying compatible with component ports (DUR002, DUR003).

## Decision

The project **adopts Symfony Messenger** as the **transport and dispatch** mechanism for these application flows when the host is Symfony (Durable bundle).

### Typical roles

- **Workflow resume**: messages triggering another engine pass (`WorkflowRunMessage` or equivalent) after an activity completed, a timer is due, or a signal/update was appended to the journal.
- **Activities**: messages representing activity work to run on a dedicated worker (`ActivityMessage` or equivalent), with retry policy aligned with **DUR011**.
- **Timers**: wake messages to evaluate due timers and resume the workflow if needed.
- **Signals / updates**: delivery of business messages to handlers that append to the journal then request resume.

### Principles

- Messenger **handlers** remain **adapters**: they call component **ports** and **engine**, not the reverse in the domain.
- **Transport configuration** (`framework.messenger`) defines queues (names, DSN, retry, failure); the bundle exposes transport names or options consistent with documentation.
- Message **serialization**: align with **DUR007** (Symfony Serializer) for Messenger envelope payloads when DTOs are serialized.
- **Stamps**: use dispatch stamps carefully (e.g. ordering relative to the current bus) to avoid unexpected races between handlers; sensitive scenarios are **tested** (DUR009, DUR015).

### Relationship to Temporal (DUR019, DUR024)

- **Messenger** carries **application** resume and execution logic on the PHP Symfony side.
- The **Temporal gRPC bridge** (journal, worker) remains a **separate** layer: both can coexist in one deployment (see convergence invariant in DUR019); Messenger does not replace **GetWorkflowExecutionHistory** or Temporal **worker poll**.
- As **[DUR024](DUR024-temporal-native-execution-and-interpreter.md)** is implemented, some resume/activity paths may move to **Temporal activity** and **workflow** polling; this ADR should be updated to list **remaining** Messenger responsibilities.

### Non-distributed mode / tests

- Tests and the **In-Memory** runner (DUR005, DUR016) may **bypass** Messenger or use an **`in-memory://`** transport to stay deterministic and fast (DUR010).

## Consequences

- The **`symfony/messenger`** dependency is expected for the bundle and target Symfony applications.
- META documents may detail message class names and YAML configuration without duplicating this ADR.

## Relationship to workflow and activity authoring (DUR022, DUR023)

- **Messenger** delivers **resume** and **activity** work to workers; it does **not** replace **`WorkflowEnvironment`** or **`ActivityInvoker`** — those remain the workflow author API (**DUR022**, **DUR023**).
- Activity **handlers** resolve **`#[ActivityMethod]`** implementations with normal **DI** on the worker (**DUR023**).
