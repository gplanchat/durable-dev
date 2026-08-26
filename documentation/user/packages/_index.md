---
title: Packages
weight: 5
---

# Packages

Durable ships as three Composer packages. They stack: the library on its own, plus the framework
integration you use, plus the driver that gives you real durability.

| Package | Brings | Needs |
|---|---|---|
| `gplanchat/durable` | workflows, activities, timers, event journal, in-memory backend | PSR clock, PSR logger |
| `gplanchat/durable-bundle` | Symfony wiring, worker commands, profiler panel | the library, Symfony framework-bundle and Messenger |
| `gplanchat/durable-bridge-temporal` | the Temporal driver, over gRPC | the library, `ext-grpc`, a Temporal cluster |

---

## `gplanchat/durable` — the library

```bash
composer require gplanchat/durable
```

The engine and the whole domain: `WorkflowEnvironment`, activities, timers, side effects, signals,
queries, updates, child workflows, the event journal, and the value objects that describe
scheduling options.

Two runtime dependencies — `psr/clock` and `psr/log`. **No framework.** You can drive it from a
plain PHP script, an Laminas application, a console tool, or a test.

It ships an **in-memory backend** that runs everything in one process. That is what your unit
tests use, and it needs nothing installed.

> [!NOTE]
> The in-memory backend keeps no state between processes. It is for tests and local exploration,
> not for a workflow that has to survive a deploy. See [Backends](../backends/).

---

## `gplanchat/durable-bundle` — the Symfony integration

```bash
composer require gplanchat/durable-bundle
```

What it does that you would otherwise write by hand:

- **Autoconfiguration.** Classes carrying `#[Workflow]` and `#[Activity]` are registered on their
  own; you do not list them in a container file.
- **Messenger wiring.** Workflow resumes and activity dispatches are routed to the transports you
  name in `durable.yaml`, so a workflow that suspends resumes through your existing queues.
- **Worker commands.** Console entry points to run workflow and activity workers.
- **Profiler panel.** In the Symfony toolbar: each execution, its journal, and the timeline of
  activities — including which attempt failed and why.

Configuration is one file, documented key by key in the
[configuration reference](../configuration/).

---

## `gplanchat/durable-bridge-temporal` — the Temporal driver

```bash
composer require gplanchat/durable-bridge-temporal
```

Talks to a Temporal cluster **directly over gRPC**. There is no official Temporal PHP SDK in the
dependency tree, and no RoadRunner — the protobuf definitions are vendored and the workers are
plain PHP processes.

What it adds over the in-memory backend:

- executions that survive process restarts, deploys and crashes;
- server-side retry policies, so a failing activity is retried even if the worker is gone;
- cron schedules, search attributes, and cross-process visibility in the Temporal UI;
- a read-through event store, so the profiler shows a real execution's history.

It needs `ext-grpc` and a reachable cluster. For local work, one command is enough:

```bash
temporal server start-dev --namespace durable-test --port 7233
```

> [!NOTE]
> Cron schedules and search attributes are Temporal capabilities. They have no in-process
> equivalent and the in-memory backend refuses them explicitly rather than ignoring them silently.

---

## Which do I install?

{{< columns >}}

**A Symfony application, in production**
All three. The library for the engine, the bundle for the wiring, the bridge for durability.

<--->

**A Symfony application, tests only**
Library and bundle. The in-memory backend needs no server, so your test suite stays fast and
hermetic.

<--->

**No framework**
The library, and the bridge if you want durability. You wire the workers yourself.

{{< /columns >}}

---

## One codebase, one behaviour

The two backends run the **same fiber driver** and the **same activity execution path**. A workflow
you tested in memory behaves the same way against Temporal — retry counting, failure
classification, cancellation and compensation included.

Where a capability genuinely has no in-process equivalent, the in-memory backend **fails with an
explicit message** rather than pretending. The differences are listed in
[Backends](../backends/#capability-matrix).

---

## Monorepo and releases

Development happens in a single repository, `gplanchat/durable-dev`. Each package is published to
its own read-only repository by a split, so `composer require` pulls a small package rather than
the whole tree.
