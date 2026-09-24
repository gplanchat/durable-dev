---
title: Glossary
weight: 50
---

# Glossary

The words this guide uses in a precise sense. Each entry says what the word means here, and where
the mechanism behind it is described.

**Execution** — one durable run of a workflow, identified by an execution id, from its start to its
completion, failure or cancellation. It survives the death of the process that was running it:
another worker resumes it from its journal.

**Workflow** — the PHP class that describes an execution's steps, marked `#[AsWorkflow]`, with one
`#[AsWorkflowMethod]`. Its code must be deterministic, because it is replayed: it asks the
environment for time, randomness and I/O instead of taking them itself. See
[Creating a workflow](../workflows/).

**Activity** — a unit of side effect (an HTTP call, a database write, an e-mail) declared on a
contract interface with `#[AsActivity]` and implemented by a class carrying `#[AsActivityHandler]`.
An activity runs once, on a worker, and its result is written to the journal; a replay reads the
result instead of running it again. See [Creating activities](../activities/).

**Journal** (also *event store*) — the append-only sequence of events that records everything an
execution decided and received: activities scheduled and completed, timers, signals, side effects,
its end. It is what a resume reads. Three storages hold it: a SQL database (Doctrine DBAL or
Illuminate), Temporal's own history, or memory for tests. See [Backends](../backends/).

**Replay** — how an execution resumes: the workflow code runs again from its first line, and every
`await` is answered from the journal until the code reaches the first step with no recorded
outcome, which is where real work starts again. Replay is why the code must be deterministic. See
[Concepts](../concepts/).

**Slot** — the position of a call in the workflow's code: the third activity, the first timer, the
second side effect. Replay matches a call to its recorded outcome by slot; since DUR042 the guard
also compares the call's name and payload, and fails the execution rather than serve one call's
result to another (the **divergence guard**). See [Changing a running workflow](../deploying/).

**Cursor** — the position from which the journal is read. Replay walks the journal forward with a
cursor, one event at a time; the run catalogue also pages its lists with an opaque cursor, so a
link to "the next page" stays valid while runs keep arriving.

**Side effect** — a small non-deterministic value the workflow needs (an id, a random number, now's
timestamp) recorded once through `sideEffect()` and served from the journal on replay. For
anything with I/O, use an activity.

**Timer** — a durable wait: `sleep()` or a deadline on `await()`. It is journaled when set and when
it fires, so a resume knows whether the wait is over without a process having stayed alive to count
it.

**Signal, query, update** — the three ways in from outside an execution. A *signal* pushes a fact in
and is journaled; a *query* reads state without changing it; an *update* pushes a fact in and waits
for the workflow's answer. See [Creating a workflow](../workflows/).

**Backend** — where the journal lives and who schedules the work: **in memory** (tests), **DBAL**
or **Illuminate** (one SQL database, the application's own queue), or **Temporal** (a cluster; the
bridge speaks gRPC and does not use the official PHP SDK). Workflow code does not change between
them. See [Backends](../backends/).

**Worker** — the process that pulls work: it replays workflows, runs activities and serves Nexus
operations. On Symfony it is `messenger:consume` on the durable transports; on Laravel the
application's queue worker, or on Temporal `durable:temporal-worker` and its `--role=activity`; on Magento `bin/magento durable:worker`.
Nothing progresses without one. See [Getting started](../getting-started/).

**Child workflow** — an execution started by another one, which awaits its result the way it awaits
an activity. **Continue-as-new** closes an execution's journal and opens a fresh one for the same
work with the state it chooses to carry, so a long-lived workflow's journal does not grow forever.

**Nexus operation** — an operation served by another service, with its own contract, that a workflow
calls the way it calls an activity. Temporal only: the journal backends refuse it by construction.
See [Nexus operations](../nexus/).

**Change point** — a named fork in workflow code (`version()`) that lets a new deploy behave
differently for executions started after it while executions in flight keep the old branch. See
[Changing a running workflow](../deploying/).
