---
title: Packages
weight: 5
---

# Packages

Durable is a core, an optional framework integration, and a choice of backend. You take what you
need: the library alone is enough to write and unit-test a workflow, and nothing above it changes
the workflow code — only where the execution is recorded.

| Package | Brings | Needs |
|---|---|---|
| `gplanchat/durable` | workflows, activities, timers, event journal, in-memory backend | `psr/cache` |
| `gplanchat/durable-bundle` | Symfony wiring, worker commands, profiler panel | the library, Symfony framework-bundle and Messenger |
| `gplanchat/durable-bridge-temporal` | the Temporal driver, over gRPC | the library, `ext-grpc`, a Temporal cluster |
| `gplanchat/durable-bridge-dbal` | durable execution on one SQL database | the library, Doctrine DBAL 3 or 4, `symfony/lock` |
| `gplanchat/durable-plugin` | a Sylius admin dashboard for workflow runs | Symfony, `knplabs/knp-menu`; Sylius 2.x to appear in its menu |

The two bridges are **alternatives**, not layers: you pick Temporal or DBAL, never both.

---

## `gplanchat/durable` — the library

```bash
composer require gplanchat/durable
```

The engine and the whole domain: `WorkflowEnvironment`, activities, timers, side effects, signals,
queries, updates, child workflows, the event journal, and the value objects that describe
scheduling options.

One runtime dependency — `psr/cache`, and even that pool is optional: it memoises activity
contract resolution, and `ActivityContractResolver` works without one. **No framework.** You can
drive the library from a plain PHP script, a Laminas application, a console tool, or a test.

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

## `gplanchat/durable-bridge-dbal` — the SQL backend

```bash
composer require gplanchat/durable-bridge-dbal
```

Durable execution on **one SQL database**, with no orchestration cluster and no `ext-grpc`. The
decision behind it is [**DUR030**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md).

The replay interpreter, the workflow ports and the command buffer are untouched: this bridge only
makes the three process-local stores persistent — the event journal, the workflow metadata, and
the parent links between child workflows. Workflow and activity code is byte-for-byte what runs on
Temporal or in memory.

| Kept | Given up, against Temporal |
|---|---|
| Workflow classes, activities, `WorkflowEnvironment` | Distributed task queues — resumes ride Symfony Messenger |
| Signals, queries, updates | Server-side scheduling; timers ride Messenger `DelayStamp` |
| Cancellation and compensation semantics | Server-side task serialisation, replaced by an application lock |
| Replay determinism and the event journal | History retention, visibility API, the Temporal UI |

Choose it when durability matters and running a cluster does not: one database you already back
up, one migration, and no extension to compile.

---

## `gplanchat/durable-plugin` — the Sylius dashboard

```bash
composer require gplanchat/durable-plugin
```

An admin dashboard for Sylius: the list of workflow runs with search and status filters, and a run
detail view with timeline lanes and recent events. Timeline labels prefer the human-readable
`ActivityType.name` and fall back to technical IDs only when there is nothing better.

It **observes**; it does not execute. It imports no class from the library core, so it adds to the
bundle rather than replacing it — install both.

> [!NOTE]
> Live data comes from `gplanchat/durable-bridge-temporal`, which is a `suggest` rather than a
> `require`: it needs `ext-grpc`, which most Sylius hosts do not ship, and an observation dashboard
> is not worth forcing a PHP rebuild over. Without the bridge the plugin still installs, the route
> and the menu entry still work, and the dashboard renders its degraded state instead of live runs.

---

## Which do I install?

Every command below is the one the chooser on the [home page](/) hands you, written out in full.

| Your situation | Command |
|---|---|
| Learning, or unit tests only | `composer require gplanchat/durable` |
| No framework, one SQL database | `composer require gplanchat/durable gplanchat/durable-bridge-dbal` |
| No framework, Temporal cluster | `composer require gplanchat/durable gplanchat/durable-bridge-temporal` |
| Symfony, tests only | `composer require gplanchat/durable-bundle` |
| Symfony, one SQL database | `composer require gplanchat/durable-bundle gplanchat/durable-bridge-dbal` |
| Symfony, Temporal cluster | `composer require gplanchat/durable-bundle gplanchat/durable-bridge-temporal` |
| Sylius, tests only | `composer require gplanchat/durable-bundle gplanchat/durable-plugin` |
| Sylius, one SQL database | `composer require gplanchat/durable-bundle gplanchat/durable-plugin gplanchat/durable-bridge-dbal` |
| Sylius, Temporal cluster | `composer require gplanchat/durable-bundle gplanchat/durable-plugin gplanchat/durable-bridge-temporal` |

The bundle pulls the library in transitively, which is why the Symfony and Sylius lines do not
name it. Without a framework you name the library yourself, and you wire the workers yourself too.

---

## One codebase, one behaviour

Every backend runs the **same fiber driver** and the **same activity execution path**. A workflow
you tested in memory behaves the same way against DBAL or Temporal — retry counting, failure
classification, cancellation and compensation included.

Where a capability genuinely has no equivalent, the backend **fails with an explicit message**
rather than pretending. The differences are listed in
[Backends](../backends/#capability-matrix).

---

## Monorepo and releases

Development happens in a single repository, `gplanchat/durable-dev`. Each package is published to
its own read-only repository by a split, so `composer require` pulls a small package rather than
the whole tree.
