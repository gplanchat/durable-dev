## Why

Four backends implement `WorkflowRunCatalogInterface` today — in-memory, DBAL, Illuminate and
Temporal — and the component promises that each of them answers the same questions in the same
vocabulary. Read side by side, they do not.

The sharpest instance is **identity**. A caller names an execution once:

```php
$dispatcher->dispatchNewWorkflowRun('order-000000017', 'OrderFulfilment', $payload);
```

Where that name ends up depends on the backend:

| Backend | The caller's name is stored in | Surfaced on `WorkflowRunDescription` as |
|---|---|---|
| In-memory | the key of the run map | `runId` |
| DBAL | `durable_workflow_runs.execution_id`, the primary key | `runId` |
| Illuminate | the same column, the same shape | `runId` |
| Temporal | the `durableExecutionId` memo on `WorkflowExecutionStarted` | **nowhere** |

On Temporal, `runId` holds the server's own run id — a UUID that is *replaced on every
continue-as-new* — and `groupId` holds a workflow id the component **derives** from the caller's
name and cannot derive back: `'durable-' . substr(preg_replace('/[^a-zA-Z0-9._-]/', '-', $id), 0, 900)`.
The transformation is not injective and not reversible.

The consequence is not academic. `RunDashboard::pick()` selects a run by comparing `runId`, so the
`?run=` link in the admin means the caller's business name on three backends and an opaque,
short-lived UUID on the fourth. A run has no address that survives being sent to a colleague, and a
surface that renders no HTML — an API Platform state provider, a Filament panel — has no identifier
to expose at all.

### The divergence is wider than identity

| | In-memory | DBAL | Illuminate | Temporal |
|---|---|---|---|---|
| Cursor shape | the run id, **unencoded** | `base64(startedAt \0 executionId)` | same as DBAL | `base64(server page token)` |
| Cursor resumption | `array_search` over the ordered list | keyset predicate | keyset predicate | opaque token |
| `groupId` | absent | absent | absent | present |
| `readHistory()` returns `[]` | execution unknown | execution unknown | execution unknown | execution unknown **or** the description lacked a `groupId` |
| Filters accepted | outcome | outcome | outcome | outcome |
| Total count | none | none | none | none |

The port's own docblock says a cursor is opaque and that returning it to the same catalogue is the
only thing an caller may do with it. Three backends honour that by encoding; the in-memory one
returns a bare business identifier that ends up in a URL. Nothing catches this, because the property
that matters — *a cursor from page n yields page n+1, with no gap and no repeat* — is nowhere stated
as a requirement.

### And it is not under test where it diverges

The component ships four conformance suites and they are subclassed twelve times:

| Port | In-memory | DBAL | Illuminate | Temporal |
|---|---|---|---|---|
| `EventStoreInterface` (+ replay) | yes | yes | yes | **no** |
| `WorkflowMetadataStore` | yes | yes | yes | **no** |
| `WorkflowRunCatalogInterface` | yes | yes | yes | **no** |
| `ChildWorkflowParentLinkStore` | yes | yes | yes | **no** |

Twelve of sixteen cells, and the four empty ones are the same backend — the only one whose data
model actually differs. The three that are covered share a journal and a table, so parity between
them was never in doubt. Parity is proven exactly where it was already free, and unproven where it
is at stake. ADR **DUR041** states the suite is "implemented for all four ports", and a docblock in
the core says the two Temporal stores extend it; neither is true today.

## What Changes

- **`WorkflowRunDescription` gains `executionId`**, the name the caller gave the execution. It holds
  the same value on all four backends, by construction: it is the argument that already becomes
  `execution_id` on the SQL backends and the `durableExecutionId` memo on Temporal. `runId` keeps
  its meaning — the backend's own identity for one run — and stops being asked to be two things.
- **`WorkflowRunCatalogInterface` gains `findRun(string $executionId): ?WorkflowRunDescription`.**
  A run a surface lists SHALL be openable by that identifier alone, without paging to find it, and
  an identifier that names nothing SHALL be distinguishable from a run with no history.
- **A cursor is specified by its property, not by its shape.** What a backend encodes is its
  business; that a page boundary loses no run and repeats none, while runs are being written, is
  the component's. The in-memory backend's bare identifier stops being acceptable because the
  property, not the encoding, becomes the requirement.
- **`readHistory()` gets one meaning for `[]`.** An empty history SHALL mean "this execution
  recorded nothing readable", never "the description you handed me was not usable". A description a
  catalogue produced SHALL always be readable by that same catalogue.
- **Every backend runs every conformance suite.** The four missing Temporal subclasses are written,
  and the suites gain the parity properties above so that they test uniformity rather than
  re-testing three implementations of one journal.
- **BREAKING** yes, for anyone implementing a storage port — adding a constructor parameter to
  `WorkflowRunDescription` and a method to `WorkflowRunCatalogInterface`. No application that only
  *uses* Durable is affected. A Rector rule rewrites the positional construction sites it can derive
  (`executionId === runId` wherever `groupId` is absent); a third-party backend must state what its
  own execution identity is, which no tool can invent. `UPGRADE.md` carries both.

### Why `durableExecutionId` and not one of the three obvious candidates

`runId` alone cannot be the identity: it is a UUID on Temporal, replaced at every continuation, and
`readHistory()` cannot use it without a workflow id. `groupId` alone cannot: it is absent on three
backends and, on the fourth, it is a lossy derivation of the caller's name rather than the name. The
pair `groupId/runId` is what Temporal's history read needs, but it is not a resource identifier —
half of it is null on three backends, and both halves change under the operator's feet.

The memo is the only value that is the caller's own, identical across backends, and fixed for the
life of the execution. Resolving it back costs no search: `workflowId()` is a pure function of it,
so a lookup is one `DescribeWorkflowExecution`, and on the SQL backends it is a primary-key read.

### Not in scope

- **Normalising `WorkflowRunEvent::details`.** It carries the backend's own vocabulary on purpose;
  issue #261 asks for a typed *phase* instead, which is a separate and better-defined change.
- **A total count.** No backend can produce one cheaply, and pretending otherwise is what
  `PartialPaginatorInterface` exists to avoid. Filtering beyond outcome — issue #264 — belongs to
  the same later change as the count.
- **Linking the runs of a continue-as-new chain on the SQL backends.** Real, tracked separately,
  and orthogonal: this change settles what identifies one run, not how two are related.
- **Any HTTP or admin surface.** What is settled here is the port and its conformance. The Sylius
  split proposed in issue #264 consumes it and does not belong to it.
