# DUR041 — Store parity is a suite every adapter runs, and the in-memory store is the reference

## Status

Accepted — **`EventStoreInterface` implemented**; the three other ports remain to be written.

## Context

Four interfaces make up the storage side of the hexagon, and three families already sit behind them
with their own constraints:

| Port | In-memory | Temporal | DBAL |
|---|---|---|---|
| `EventStoreInterface` | `InMemoryEventStore` | `TemporalJournalEventStore`, `TemporalReadThroughEventStore` | `DbalEventStore` |
| `WorkflowMetadataStore` | `InMemoryWorkflowMetadataStore` | — | `DbalWorkflowMetadataStore` |
| `ChildWorkflowParentLinkStoreInterface` | `InMemoryChildWorkflowParentLinkStore` | — | `DbalChildWorkflowParentLinkStore` |
| `WorkflowRunCatalogInterface` | — | yes | `DbalWorkflowRunCatalog` |

A fourth family is on the table ([OST003](../ost/OST003-php-ecosystem-integrations.md) §3), and the
question it raised is what stops two implementations of the same port from drifting apart.

**One test answers that today, for one port, between two named stores.**
`DbalBackendParityTest` runs a real workflow — an activity, a timer, two side effects, one of them
carrying a non-scalar payload — against `InMemoryEventStore` and `DbalEventStore`, then compares
what replay reads back from each. It is the right test. It catches the failure that matters, and
its own docblock names it: a SQL round trip that deforms a payload **breaks replay silently, not at
write time**.

What it is not is reusable. Three things follow from that:

- **It is pairwise.** Two stores, compared to each other, with neither named as correct. A third
  store means a third comparison; a fourth means six. There is no defined truth to compare against,
  only a growing set of agreements.
- **Three of the four ports have no parity test at all.** `DbalStoresTest` and
  `DbalWorkflowRunCatalogTest` exercise the DBAL implementations; nothing states what any
  implementation of `WorkflowMetadataStore`, `ChildWorkflowParentLinkStoreInterface` or
  `WorkflowRunCatalogInterface` must do.
- **Temporal's two `EventStoreInterface` implementations have never been checked against
  anything.** DUR029's read-through store converts Temporal history into domain events — a
  conversion is exactly where a round trip deforms.

## Decision

**Each store port ships a conformance suite, and an adapter proves itself by running it.**

### The in-memory store is the reference

Every adapter is compared to `InMemoryEventStore` and its siblings, never to whichever adapter
happens to be next to it. A reference names what *correct* means and keeps the number of test
classes equal to the number of adapters; pairwise agreement defines nothing and grows as the square.

**The reference runs the suite too.** A reference that is not itself checked is a definition, not a
guard.

### What the suite asserts

For `EventStoreInterface`, the cases the current parity test already earns, plus the ones its shape
implies:

- **Order** — `readStream()` yields in insertion order.
- **Fidelity** — event class and payload survive the round trip. The volatile-field `scrub()` helper
  that `DbalBackendParityTest` carries moves into the suite; it is the part that makes two
  executions comparable at all.
- **Single pass** — `readStream()` may return a generator. Consuming it once must suffice, and
  calling it again must restart it. The current test pins the first half of that; the second half is
  the one an adapter is most likely to get wrong.
- **Isolation** — a stream carries that execution's events and no others.
- **Counting** — `countEventsInStream()` equals the length of the stream, without the adapter having
  to materialise it.
- **Recorded time** — `readStreamWithRecordedAt()` yields the same events in the same order.
  `recordedAt` is nullable by contract, so the suite asserts the shape, not a value.
- **Absent execution** — an unknown execution id yields an empty stream and a count of zero, not an
  error.

The other three ports get the same treatment, and their case lists are part of writing the suite,
not of this ADR.

### It ships in the package that owns the port

The suite lives in `Gplanchat\Durable\Testing\`, inside `gplanchat/durable`, with
`phpunit/phpunit` declared under `suggest` — the arrangement
`Gplanchat\Durable\Bundle\Testing\DurableBundleTestTrait` already uses. A bridge maintained outside
this monorepo can then run it, which a suite parked in `tests/` cannot offer.

### Two tiers, named rather than silently skipped

- **Port-level** cases need a store and nothing else. Every adapter runs them.
- **Replay-level** cases drive a real workflow through `InMemoryWorkflowRunner` and read slots back
  through `EventStoreHistorySource`. The Temporal adapters answer to a live server and belong to the
  integration suite; they run this tier there, not in `unit`.

The split is declared so that a bridge running half the suite is a visible fact rather than an
omission nobody notices.

### `NullEventStore` is outside conformance

It implements the port so a signature can be satisfied in distributed mode, where the methods are
never called. It stores nothing and cannot pass a fidelity case. Stating that here keeps a later
reader from "fixing" it into the suite.

## Consequences

- **A fourth adapter starts by extending a class.** OST003 §3 named a conformance suite as the
  prerequisite for a Laravel backend rather than the adapter being the hard part; this is that
  prerequisite.
- **Three ports gain coverage they have never had.** `DbalStoresTest` and
  `DbalWorkflowRunCatalogTest` become subclasses of the relevant suite, and the in-memory
  implementations get their first contract test.
- **`DbalBackendParityTest` is superseded by its own subclass.** Its two cases and its `scrub()`
  become suite material; nothing it proves is lost.
- **Temporal's read-through store gets checked for the first time.** This is the consequence most
  likely to surface a defect rather than confirm one — DUR029 conversion is where the shapes could
  already have diverged, and the suite will say so.
- **The `phpunit/phpunit` cost was already paid.** This ADR listed it as a real cost; it is not one.
  `gplanchat/durable` already carries `phpunit/phpunit` under `require-dev` and already ships
  `Testing\DurableTestCase`, `Testing\ActivitySpy` and `Testing\WorkflowTestEnvironment`. The
  suite joined a `Testing/` namespace that existed, and the `suggest` line was extended rather than
  added.
- **A conformance suite freezes the contract.** Every port change becomes a change to the suite and
  to every adapter behind it. That is the point, and it will read as friction on the first port
  change that is not a bug fix.
- **The suite cannot prove what it does not know to ask.** It pins the shape the reference produces
  today. **Adding an event type means adding a case** — the guard is only as wide as its case list,
  and that is the follow-up rule this ADR leaves behind.

## What the implementation added

Three things the decision did not foresee, recorded because each is a rule rather than a detail:

- **The two tiers are two classes, not a flag.**
  `EventStoreReplayConformanceTestCase extends EventStoreConformanceTestCase`. An adapter that can
  drive a workflow in-process extends the second and inherits both tiers; one answering to a server
  extends the first and replays the second in the integration suite. A skipped case would have been
  the silent omission this ADR set out to prevent — a class declaration is not skippable.
  The reference does **not** extend the replay case: a store cannot be differenced against itself.
- **The coverage guard compares against the directory.** `testEveryEventTypeIsCoveredOrExplicitly-
  Excluded` globs `src/Durable/Event/*.php` and requires every class to be either a fixture or in
  `eventTypesOutsideTheJournal()` — today the five Nexus events, which `EventDataMapper` does not
  map and a SQL journal never sees (DUR036). Adding an event type now fails the suite until somebody
  chooses a side.
- **The fixtures needed hostile values, and only a mutation said so.** The first fixture set passed a
  deliberate `JSON_NUMERIC_CHECK` mutation of `DbalEventStore` — nothing in it looked like a number.
  A SKU with a leading zero (`'0042'`), an integer past `PHP_INT_MAX` as a string, `'1e3'`, `false`,
  `null` and an empty list went in, and the mutation then failed as it should. **A fidelity fixture
  made of well-behaved values proves nothing**, and that is why these are in the suite rather than in
  a comment.

One side effect worth naming: the suite lives in `src/`, so PHPStan analyses a legitimate
`ActivityStub` call for the first time. `phpstan.neon` now `includes` the repository's own
`src/DurablePhpstan/extension.neon` — the extension that exists precisely to resolve those calls
(DUR038), and which the repository had never pointed at its own source. No new errors followed.

## References

- [DUR002 — WorkflowClient, WorkflowHistorySourceInterface, WorkflowCommandBufferInterface](DUR002-cqrs-temporal-repositories.md) — where the ports come from.
- [DUR029 — Temporal read-through event store and profiler event conversion](DUR029-temporal-profiler-read-through-event-store.md)
- [DUR030 — DBAL backend: simplified durable execution on a single SQL database](DUR030-dbal-backend-simplified-durable-execution.md) — what the DBAL backend promises, as opposed to what any store must prove.
- [DUR009 — Testing standards](DUR009-testing-standards.md) and [DUR010 — Test pyramid](DUR010-test-pyramid.md)
- [OST003 §3 — PHP ecosystem integrations](../ost/OST003-php-ecosystem-integrations.md)
