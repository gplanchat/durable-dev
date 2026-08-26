# OST001 — Alternative durable execution backends

## Status

Exploration — no decision. Feeds a future ADR extending [DUR005](../adr/DUR005-implementation-backends-temporal-and-in-memory.md).

## Opportunity

Durable currently ships two backends (DUR005): **In-Memory** for tests, **Temporal** for production. Temporal is the only production option, and it carries two costs the project pays on every deployment:

- **`ext-grpc`** — a compiled PHP extension, per environment, version-matched to `grpc/grpc`.
- **A Temporal cluster** — server, database, and the operational surface that comes with it.

Question: is there another engine Durable could speak to, and what would it actually cost?

---

## 1. What a backend must implement here

This is the part that decides everything, so it comes first.

Durable is **not** a client library that calls an orchestrator API. Its core is a **deterministic replay interpreter**: `WorkflowFiberDriver` re-runs the workflow fiber from the top on every task, reads past outcomes out of a history, and emits the new commands it discovered. A backend therefore has to satisfy three ports, not one:

| Port | What the engine must provide |
|---|---|
| `WorkflowHistorySourceInterface` | Per-slot lookup of past outcomes: activity result, timer fired/cancelled, side-effect marker, child workflow result, signal payload, update result. |
| `WorkflowCommandBufferInterface` | Accept the commands replay produced: schedule activity, start timer, record side effect, start child workflow, cancel activity, cancel timer, complete, fail. |
| `WorkflowLifecycleInterface` | Own the run outcome: suspended, cancelled, completed, failed, continue-as-new. |

Plus a worker loop that fetches tasks and posts results back.

**Are these ports Temporal-shaped?** In vocabulary, yes — the docblocks name `COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK` and friends. In code, **no**. `grep` finds, under `src/Durable/`:

- zero `use Temporal\…` / `use Gplanchat\Bridge\…` imports;
- zero inline fully-qualified references outside docblocks — every `\Temporal\…` occurrence is a `{@see}` cross-reference;
- of the 45 files mentioning "Temporal", **one** carries it in an identifier (`WorkflowDefinitionLoader::aliasForTemporalInterop()`) and three in exception or comment strings. The rest is prose.

The coupling is documentary, not structural.

That matters: it means the vocabulary is *durable-execution* vocabulary — schedule work, wait on a timer, memoize a side effect, spawn a child — which every engine in class A below expresses in some form. No neutral command layer needs to be invented first. A second bridge is a translation job, not a core refactor.

**Cost anchor.** The Temporal bridge is **4 851 LOC** across 38 hand-written files, on top of **729 generated** protobuf classes. Of the hand-written code:

- `Worker/` **1 752** — replay glue, task runner, activity execution. Roughly the shape any bridge needs.
- root + `Journal/` + `Store/` **1 268** — client, history cursor, page merging, journal event store.
- `Grpc/` + `Codec/` **854** — pure transport and payload plumbing. **An HTTP-based engine deletes most of this line, and all 729 generated classes.**
- `Profiler/` + `Messenger/` + `Port/` + `Spike/` **932** — integration and dev surface.

So: a second bridge is a **~3–4 kLOC** job for a gRPC engine, plausibly **~2–3 kLOC** for an HTTP one. Not a weekend, not a rewrite.

---

## 2. Classification: who owns control flow

The useful axis is not "how hard is the integration" — it is **where the `if` statement lives**.

- **Class A — PHP owns control flow.** The engine stores a journal and hands it back; your code decides what happens next. These engines can host Durable's programming model unchanged. *This is the only class worth studying.*
- **Class B — the server owns control flow.** The workflow is a BPMN diagram, a JSON DSL, or an ASL state machine deployed to the engine; PHP is demoted to a task worker that executes leaf steps. Durable's entire value proposition — **workflows as ordinary PHP code, with `try`/`catch` and loops** — disappears. These are not "harder integrations", they are a different product.
- **Class C — library with its own storage.** No server to bridge to. Interesting for a different reason: see §5.

---

## 3. Class A candidates — verdicts

### Durable Task / `TaskHubSidecarService` — **contraindicated** *(studied separately)*

Best structural match of anything surveyed: `GetWorkItems` / `CompleteOrchestratorTask` /
`CompleteActivityTask`, with `pastEvents` + `newEvents` in and `actions` out — the contract
`WorkflowFiberDriver` already implements. Public proto, explicitly meant for third-party SDKs, no
PHP SDK in existence. One bridge would reach the Dapr sidecar, Azure Durable Task Scheduler and
self-hosted `durabletask-go`.

Checked feature by feature in **[OST002](OST002-durable-task-backend-feasibility.md)**. Summary of
what that found:

- **No per-operation cancellation.** `cancelActivity()` would compile, run, and do something else —
  the workflow sees its cancellation, the activity runs to completion anyway. Nothing fails, nothing
  warns; the difference surfaces in the side effects of a compensation. This is the finding that
  decides the option.
- **No workflow updates, no activity heartbeats.** No parade for either.
- **Two silent-corruption traps:** the ordering of self-addressed events (the only channel for
  side-effect markers), and `carryoverEvents` dragging markers into a continue-as-new run.
- Everything else — retries, timeouts, task queues, reuse policy — is work, not loss, and most of it
  dissolves into passing our value objects through the protocol's `tags` maps.

Verdict: **contraindicated**, not impossible. The one thing it silently changes is the saga and
compensation guarantee the user documentation sells hardest.

### Restate — **the one that changes the deployment model**

Restate inverts the direction: the runtime **calls your service over HTTP**, sending the journal; the SDK replays and answers with new entries. The [service invocation protocol](https://github.com/restatedev/restate/tree/main/service-protocol) is published and versioned by content-type (`application/vnd.restate.invocation.vX`), and supports two modes — full-duplex over HTTP/2, **or plain request/response**.

That request/response mode is the interesting bit for PHP: it maps onto a normal **PHP-FPM request**. No long-running poller process, no `ext-grpc`, no compiled extension anywhere in the stack. That is a materially different operational story from Temporal, and it is the one deployment shape PHP shops are already good at.

- **For:** removes `ext-grpc` entirely; fits shared/FPM hosting; single Rust binary to operate.
- **Against:** protocol churn, and it is documented churn. The standalone spec repo was archived in Feb 2025 and folded into the main repo; the archived text describes **v1**, while Restate ≥ 1.7 gates a **v7** behind `RESTATE_EXPERIMENTAL_ENABLE_PROTOCOL_V7`. Upstream states only that "registered Restate services must use an SDK compatible with the service protocol version(s) of the running Restate server", and defers the matrix to each SDK's own documentation — **there is no protocol-level deprecation window or support policy**. A PHP SDK would have to publish and maintain that matrix itself. Restate's state/virtual-object model is also richer than Durable's ports and would be partly unused.
- **Verdict:** highest strategic upside, highest maintenance risk. Worth a spike specifically to measure protocol churn over a release or two before committing.

### Inngest — **public spec, wrong granularity**

[`docs/SDK_SPEC.md`](https://github.com/inngest/inngest/blob/main/docs/SDK_SPEC.md) is an explicit, public spec for building SDKs in new languages. HTTP: the executor POSTs the event plus memoized step results keyed by hashed step ID; the SDK answers `206 Partial Content` with opcodes, or `200` when done.

- **For:** HTTP only, spec written for exactly this use case, serverless-friendly.
- **Against:** the model is **step memoization by hashed ID**, not a slot-indexed history with typed commands. Durable's richer vocabulary — child workflows, `cancelActivity`, `cancelTimer`, continue-as-new, cancellation delivery semantics — has no clean counterpart. No stability/deprecation policy stated (`X-Inngest-Req-Version: 2`, `schema_version: 2024-05-24`).
- **Verdict:** technically implementable, but Durable would lose expressiveness. Second tier.

### Cadence — **cheap, and worth little**

Uber's pre-fork ancestor of Temporal. Same architecture, same task-poll loop, and it appears to have kept the original **decision** naming — `PollForDecisionTask` / `RespondDecisionTaskCompleted` where Temporal says `PollWorkflowTaskQueue` / `RespondWorkflowTaskCompleted`. gRPC transport is available alongside the original TChannel. *(Naming taken from client docs and a 2.6.0 javadoc, not from a direct read of `cadence-idl` — confirm before acting on it. The verdict below does not depend on it.)*

- **Cost:** regenerate protos into a `Cadence/Api` namespace, rename the calls in `Grpc/` and `Worker/`, adapt the history-event enum. Realistically the cheapest bridge on this list.
- **Value:** near zero. It reaches teams already running Cadence and nobody else, and Temporal is where that population is migrating.
- **Verdict:** do it only if a real user asks.

---

## 4. Class B — dismissed, with the reason

| Engine | Model | Why it does not fit |
|---|---|---|
| Netflix / Orkes **Conductor** | JSON DSL on the server, workers poll tasks | Control flow leaves PHP; Durable becomes an activity SDK |
| AWS **Step Functions** | ASL state machine; `GetActivityTask` / `SendTaskSuccess` | Same, plus vendor lock-in |
| **Camunda 8 / Zeebe** | BPMN deployed to the broker, gRPC job workers | Same; workflow authoring moves to a modeler |
| **LittleHorse** | Server-side workflow spec, gRPC task workers | Same |
| **Hatchet** | DAG/task orchestration on Postgres | Step graph declared to the server, not PHP-driven |
| Airflow / Prefect / Dagster / Windmill | Data & ops orchestration | Different problem entirely — scheduled pipelines, not application workflows |
| **Golem** | WASM component durability | PHP is not a first-class WASM target here |

---

## 5. Class C — the option that is not a bridge

> **Update — being built.** This is now `gplanchat/durable-bridge-dbal`; see **DUR030**.

**DBOS** makes durability a *library* concern: workflow steps are checkpointed into Postgres, no cluster, no sidecar. There is no protocol to speak to.

The relevant read is inward, not outward: Durable already owns a replay interpreter, an event journal, and an `EventStoreInterface` whose only production implementation today is Temporal's journal. **A Postgres event store would make Durable itself the engine** — DBOS-shaped, no Temporal, no `ext-grpc`, no second server.

That is very likely the cheapest path to "durable execution in production without operating a cluster", and it is a roadmap item rather than a market opportunity. It belongs in its own study.

---

## 6. PHP ecosystem — where Durable stands

- **`durable-workflow/workflow`** (ex `laravel-workflow/laravel-workflow`) — durable execution on Laravel queues, explicitly inspired by Temporal and Azure Durable Functions, `yield` as the checkpoint. No server. 1 000+ stars. This is Durable's closest competitor in PHP, and it validates the class-C thesis above: its selling point is precisely "no cluster".
- **`keepsuit/laravel-temporal`** — Laravel wrapper over the official Temporal PHP SDK, i.e. RoadRunner-based. Out of scope under **DUR006** as a matter of project policy, not merit.

Durable's differentiator remains: **Symfony-native, no RoadRunner, no official SDK dependency**. Nothing surveyed occupies that square.

---

## 7. Hypotheses to validate

1. ~~Does the `TaskHubSidecarService` vocabulary cover all of `WorkflowCommandBufferInterface`?~~ **Answered, and the option closed on it** — see [OST002](OST002-durable-task-backend-feasibility.md).
2. Does Restate's protocol churn fast enough to make a third-party SDK unsustainable? v1 → v7 across roughly 18 months is the raw signal; what matters is how many versions a given server accepts at once. (Ask upstream for the support window, or read the server's accepted-version range directly.)
3. Is `ext-grpc` actually the adoption blocker it is assumed to be? **This hypothesis orders the whole tree and is untested.**
4. ~~Is a durable-execution backend without per-operation cancellation and without workflow updates worth shipping?~~ **Answered: no** — [OST002](OST002-durable-task-backend-feasibility.md) §6.

## 8. Decision tree

```
Durable Task / Dapr — contraindicated (OST002), branch closed.

Is another protocol-level backend still wanted?
├── no  → nothing to do; DBAL (§5) already covers "no cluster"
└── yes → is ext-grpc a real adoption blocker?
          ├── yes → Restate bridge   (HTTP, PHP-FPM friendly, protocol churn risk)
          └── no  → stay on Temporal — nothing else surveyed beats it on fit

In every branch, the DBAL event store (§5) is orthogonal and cheaper:
it needs no second server at all.
```

## 9. Next step

§5 has shipped: the DBAL event store is `gplanchat/durable-bridge-dbal` ([DUR030](../adr/DUR030-dbal-backend-simplified-durable-execution.md)). It needed no protocol at all, which is why it went first.

Nothing in §3 is actionable without §7.3. DUR005 states that a third backend requires a new ADR; this study is its input, not its substitute.

## References

- [Restate service invocation protocol](https://github.com/restatedev/service-protocol/blob/main/service-invocation-protocol.md) (archived Feb 2025; now in [`restatedev/restate`](https://github.com/restatedev/restate/tree/main/service-protocol)) · [SDK/server compatibility statement](https://docs.restate.dev/operate/upgrading/)
- [`microsoft/durabletask-protobuf` — `orchestrator_service.proto`](https://github.com/microsoft/durabletask-protobuf)
- [`dapr/durabletask-go`](https://github.com/dapr/durabletask-go) · [Dapr Workflow architecture](https://docs.dapr.io/developing-applications/building-blocks/workflow/workflow-architecture/)
- [Inngest SDK specification](https://github.com/inngest/inngest/blob/main/docs/SDK_SPEC.md)
- [Cadence `WorkflowService` API](https://cadenceworkflow.io/docs/concepts/topology)
- [OST002](OST002-durable-task-backend-feasibility.md) — Durable Task feasibility, feature by feature
- [`durable-workflow/workflow`](https://github.com/durable-workflow/workflow) · [`keepsuit/laravel-temporal`](https://github.com/keepsuit/laravel-temporal)
