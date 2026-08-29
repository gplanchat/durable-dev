# DUR047 — Laravel: the host that measured before it wired

## Status

Accepted — `gplanchat/durable-laravel` is written, published as a split, and covered by a
`Laravel × PHP` matrix. What it deliberately does not serve is named below.

## Context

[OST003 §3](../ost/OST003-php-ecosystem-integrations.md) put Laravel in Tier 1 — *foreign container,
foreign queue, a package written from the bootstrap up* — and was blunt about what the entry could
not be:

> `durable-workflow/workflow` […] is durable execution on Laravel queues: `yield` as the checkpoint,
> own storage, no server, explicitly inspired by Temporal and Azure Durable Functions, 1 000+ stars.

The square marked *a durable engine for Laravel* is occupied, by something good at it. What was left
is **the backend choice**: the same workflow code against a Temporal cluster or against one SQL
database, and a mixed Symfony / Sylius / Laravel estate sharing one engine.

[OST004 §5](../ost/OST004-what-is-not-built-yet.md) registered the cost as *"a bootstrap, plus a
fourth adapter family"*. The adapter family landed first, as
`gplanchat/durable-bridge-illuminate`; this ADR is about the bootstrap.

## Decision

### Five measurements before the first line of the package

The change opened with a phase that wrote no production code. Each measurement either confirmed a
design or replaced it, and three replaced it:

- **A blocked worker is not a coroutine.** `ResumeLock::around()` held **15 worker-seconds for 4
  seconds of work** across twenty resumes of one execution. Releasing instead held 4.2 but paid 19 s
  of wall clock. Neither shape survived, because both carry a defect the numbers made visible:
  `release()` **consumes an attempt**, so at `--tries=5` fifteen resumes out of twenty landed in
  `failed_jobs` having never run, and `around()`'s wait window is a **queue-depth ceiling** dressed
  as a latency knob. The lock therefore gained a non-blocking entry point, `tryAround()`, and the
  job re-dispatches itself rather than releasing.
- **The type system does not filter lock stores.** Nine Illuminate stores implement `LockProvider`,
  including `file` — which the bridge's own documentation claimed did not — and including
  `NullStore`, whose `NoLock::acquire()` is `return true;`. Measured across four workers: `database`
  and `file` leave zero overlapping critical sections, `array` and `null` leave **fifteen out of
  twenty**. The documentation was wrong in both directions and was corrected.
- **Declaring is cheaper than scanning, and not because of the milliseconds.** An explicit list
  costs 0,14 ms and does not grow with the application; a reflection scan costs 15 ms at a thousand
  classes **and loads all of them into every process** — 1 334 declared classes against 334 — to
  find five. There is no `durable:cache`: a cached manifest beats the list by 0,11 ms, and
  `config:cache` already caches the file it would duplicate.

### One choice of backend binds every port

A journal on one backend under a catalogue on another is not a configuration, it is a fault. The
provider therefore resolves a single `match`, and a backend it does not serve is refused **by name**
at registration, naming the two it does.

### What it refuses, and each refusal has a measurement behind it

- **`null` as the lock store**, always: it grants every lock, in every deployment.
- **`array` as the lock store, under the `illuminate` backend only.** A resume runs in a worker
  separate from whatever dispatched it, so two `array` locks never see each other. Under `memory` it
  stays allowed, because it is Laravel's own testing default and excluding inside one process is
  what a test wants.
- **The `sync` queue connection**: it runs jobs inline, so a resume that dispatches another resume
  recurses in the same process until the stack ends. The Symfony side is protected by a
  `DispatchAfterCurrentBusStamp`; here it is the connection that must be a real queue.
- Nothing else. `temporal` is served; see below for what it costs and who pays.

### Temporal is served, and the bridge is suggested rather than required

`gplanchat/durable-bridge-temporal` installs **8 packages into a Laravel application, 5 of them
Symfony**, for ~36 MB — 23 of them `dependency-injection` alone — and the application loads none of
the five. The coupled part of the bridge is **8 files out of 759**: one percent carrying 36 MB for
the other 99 %.

**Refusing the combination was the wrong half of the trade**, and it was reversed: it removed the
entry OST003 §3 sells — the same workflow code against a cluster *or* one database — to avoid a
weight nobody had complained about. The bridge is therefore a `suggest`: an application that does not
select the backend never installs it, and one that does is told by name what to install.

The split is still the right shape, and still its own change: `durable-bundle` already names
`Gplanchat\Bridge\Temporal` in two of its own classes without requiring the package, so the Symfony
wiring is already spread across both and moving the eight files consolidates it.

### Nexus is served, and the refusal lives in the core

Serving a Nexus operation means answering a call from another namespace, and only the cluster routes
those. `NexusOperationRegistry::routedBy('temporal')` under the Temporal backend,
`::unavailableOn($backend)` everywhere else — and the second refuses **at registration**, naming the
backend, rather than at the first call with a caller waiting.

That refusal is in the core rather than in each host, which is the point:
`NexusHandlerPass` only catches Symfony. `DeclaredNexusOperations` is the Laravel counterpart of that
pass, and it walks the same path — `NexusContractResolver` to read the contract,
`NexusHandlerInvoker` to sit between the handler's own signature and what the registry calls. What
changes is the source: Symfony reads tags an autoconfiguration wrote, Laravel reads
`config/durable.php`, for the same reason workflows are declared there.

## Consequences

### The core did not have to change

Unlike [DUR046](DUR046-magento-a-tier-1-host-that-improved-the-core.md), where a Tier 1 host
improved the core three times, this one needed **one** addition — `ResumeLock::tryAround()`, in the
bridge — and no change to `Gplanchat\Durable\` at all. `ResumeWorkflowHandler` had already left the
Symfony bundle for the core, precisely so a host without a message bus could serve it; this package
assembles it and does not write a second one.

That is the conformance suites and the ports doing their job, and it is worth naming as a
non-event.

### Two operational facts a reader will otherwise meet as bugs

- **A Laravel application cannot run more than one Durable worker on the `sqlite` driver.** Four
  workers popping the `jobs` table produce `database is locked`, and three of the four die on their
  first job — with WAL enabled and a 60 s busy timeout.
- **A job whose worker was killed stays reserved until `retry_after`** — 90 seconds by default. A
  worker started with `--stop-when-empty` inside that window sees an empty queue and exits having
  done nothing, which reads exactly like a resume that failed. A supervised worker picks it up.

### What this ADR does not claim

That the package is finished. It has no admin dashboard — that is
`gplanchat/durable-filament`, a separate package that will require this one and never the reverse —
no Telescope watcher, and no CI bench: OST003 §1 prices a bench as paid on every upstream release
forever, and §2 sets the discipline that none is earned until somebody runs one for real.
