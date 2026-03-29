# Future opportunities

OST001-future-opportunities
===

Introduction
---

This **Opportunity Solution Tree** explores evolution opportunities for the Durable project. These hypotheses must be validated before any development.

Identified opportunities
---

### 1. Temporal as an optional driver

**Opportunity**: Use Temporal.io as a workflow backend while avoiding RoadRunner.

**Update (journal persistence)**: An optional **EventStore** backed by a Temporal **journal workflow** (gRPC only, no Temporal PHP SDK) is implemented under **`src/Bridge/Temporal`** (`Gplanchat\Bridge\Temporal`, published as `gplanchat/durable-bridge-temporal`); see **[ADR014](../adr/ADR014-temporal-journal-eventstore-bridge.md)**. Full **workflow backend** replacement (`WorkflowBackendInterface` → Temporal orchestration) remains out of scope here.

**Decision (historical)**: The `WorkflowBackendInterface` interface still allows evolving the **orchestration** backend without changing the Durable core.

**Candidate solutions** (to validate):
- `WorkflowBackendInterface` already in place — local adapter (EventStore + Messenger)
- Temporal adapter (future): gRPC client, standard PHP workers (without RoadRunner)
- Constraint: the Temporal PHP SDK targets RoadRunner for workflows; integration without RR would need a hybrid or custom approach

**Hypotheses to validate**:
- Can the Temporal PHP SDK be used without RoadRunner for activities only?
- Is a hybrid mode (local workflows, Temporal activities) relevant?

### 2. Multi-transport and workflow re-dispatch

**Opportunity**: Full distributed mode where workflows and activities run in separate processes.

**Candidate solutions**:
- Workflow = Messenger job that re-dispatches after each activity (Durable Workflow model)
- Dedicated consumer `durable:workflow:consume` waking workflows on activity completion

**Hypotheses**:
- Is re-dispatch complexity acceptable for scalability gains?
- Impact on latency for short workflows?

### 3. Advanced timers

**Opportunity**: Timers based on a real clock (cron, absolute date) rather than relative delays.

**Candidate solutions**:
- Extend `ExecutionContext::delay()` with `delayUntil(DateTimeInterface)`-style signatures
- Integration with a persisted timer table (Dbal) for post-crash resume

**Hypotheses**:
- Are absolute timers a priority use case?
- How to replay timers without multiple executions?

### 4. Observability

**Opportunity**: Metrics, traces, and structured logs for workflow monitoring.

**Candidate solutions**:
- OpenTelemetry integration
- Domain events exposed for tracing
- Metrics (execution duration, failure rate, queue depth)

Decision tree
---

```
Goal: Durable component evolution
├── Temporal driver?
│   ├── Yes → WorkflowBackend interface + Temporal adapter (no RR)
│   └── No → Keep local implementation only
├── Full distributed mode?
│   ├── Yes → Workflow re-dispatch + dedicated ADR
│   └── No → Current inline mode sufficient
├── Advanced timers?
│   ├── Yes → Extend delay + persistence
│   └── No → Current delay(seconds) sufficient
└── Observability?
    ├── Yes → OpenTelemetry / metrics
    └── No → Basic logging
```

References
---

- [PRD001 - Current state](../prd/PRD001-current-component-state.md)
- [ADR005 - Messenger](../adr/ADR005-messenger-integration.md)
- [OST → ADR] Technical decisions from this OST will lead to new ADRs
