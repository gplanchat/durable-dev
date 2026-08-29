# DUR048 — One projection, two chromes: presentation is decided beside the model

## Status

Accepted

## Context

DUR037 made run observation a **projection** read through `WorkflowRunCatalogInterface`; DUR043 made
its write side a port too. Both settled *what a backend can be asked*. Neither settled what a
surface does with the answer — and by the time two hosts rendered a dashboard, that gap had produced
two different products over one journal.

`gplanchat/durable-plugin` (Sylius) and `gplanchat/durable-magento` were each written on their own,
and each ended up with a different half of the same model:

| Panel | Sylius | Magento |
|---|---|---|
| Backend health, named and dated | yes | **never probed** |
| A journal that cannot outlive the request, said out loud | absent | yes, in prose |
| Counters per outcome | yes | absent |
| One line per action | yes, as stacked blocks | yes, **placed in time** |
| A queue interval told apart from a working one | **absent** | yes, per interval |
| A recorded payload that does not encode | expander onto nothing | degraded to a line |

**Neither surface was the poorer one throughout**, and that is what made this an arbitration rather
than a copy. Sylius alone probed the backend and counted outcomes; Magento alone positioned actions
in time, told a queue apart from work, and survived a bad byte in a payload.

Four defects were measured rather than assumed, and each is a consequence of the gap rather than of
a mistake in either surface:

- Magento's listing never called `checkHealth()`. An unreachable cluster rendered a serene empty
  grid — the worse of the two possible errors, since the operator concludes there is nothing to see.
- Sylius passed a payload to `json_encode` with no partial-output tolerance. One byte that is not
  valid UTF-8 returned `false`, and the expander opened onto nothing — on the screen an operator
  reaches for last.
- On a Sylius page the same event read at two different times: the frieze tooltip carried the
  event's own zone, the row below it Twig's server zone. Invisible under UTC, which is what CI runs.
- `ProcessDetail::getTimeline()` — the richer of the two implementations, the one worth keeping —
  had **no test at all**.

Two more surfaces are planned: a Filament panel and an API Platform one. Neither has Twig, phtml or
CSS. Whatever settles this has to survive a JSON output, or it settles nothing.

## Decision

### The projection lives in the component, beside the observation model

`RunTimeline` and its `TimelineAction` / `TimelineSegment` / `TimelineEvent`, `RunDashboard`, and
`RecordedDetails` live in `Gplanchat\Durable\Observation`. Grouping events into actions, ordering
them, telling a queue interval apart from a working one, naming an action on every row it owns, and
wording a duration are decided **once**.

The precedent was already in place and was argued the same way: `ReadableDuration` is formatting, it
lives beside the observation model, and it does so because a threshold that must read the same on
every host is decided once. A timeline projection is the same kind of fact at a larger size.

`RunDashboard` is the former `RunDashboardView` of the Sylius plugin, **moved rather than copied**.
It never had anything of Sylius in it — it depended on the port and the observation DTOs and nothing
else. It was in the wrong package, not the wrong shape.

### It measures; it does not draw

Everything the projection carries is in **seconds**: `span`, `offset`, `duration`. The Magento block
it came from returned floats from 0 to 100 — CSS widths. Promoting that as-is would have put the
component in the business of drawing for a surface that renders no markup.

Scaling is the host's, and so is the rule that goes with it: *a four-millisecond queue does not draw
wider than six milliseconds of work.* That constraint is about drawing, so it binds whoever turns a
duration into a length — in both current hosts, a uniform `min-width`, which makes two intervals
equal below the threshold and never inverts them.

### What several hosts must say identically is said once

Tooltips (`TimelineSegment::$title`, `TimelineEvent::$title`), the rendering of a recorded payload
(`RecordedDetails`), and the name an action lends to each of its rows (`TimelineEvent::$actionLabel`)
moved up with the geometry. Leaving them to the host is what made two surfaces word the same second
differently. The raw fact stays on `$event->details` for a surface serving data rather than a page.

### Backend health has three states, not two

`BackendHealth::$ephemeral` distinguishes *the backend answers and its journal does not outlive the
request that renders the page* from reachable and from unreachable. An empty list reads identically
under all three, and each demands a different sentence: nothing ran, go and restart something, or
this is the correct answer. It was already known — in prose, in one catalog's message and in one
hand-written Magento banner — and a sentence is not a state: no surface can read it to decide what
to show, which is why two hosts out of three did not.

The default is `false`. The three catalogues that write outside the process — SQL, Illuminate,
Temporal — declare nothing that is true of them by construction.

### Counters count the set the list pages through, and name it

Not the application's history. A heading reading `Total` above a twenty teaches an operator that an
application with five hundred runs has twenty.

The **scope differs by host, because the pagination does**: Sylius fetches a page and counts it;
Magento's standard grid pages by offset inside a bounded window, so the set an operator walks is the
window. Both name what they cover. `RunDashboard::outcomeCounters()` is public so that a host
paging differently still gets every bucket — counting by hand is what leaves `continued_as_new` in
the total and in no bucket.

### The em dash is a rendering of absence, in tables only

Two absences look alike and are not:

- **The backend has no such notion** — a task queue on a backend that has none. No column at all. An
  empty column would teach the operator that this run has no queue.
- **This run does not have this fact** — a run still going has no end date. The column exists for its
  neighbours, and in a fixed-column layout an em dash is the rendering: a blank cell reads as a
  rendering that failed.

A card layout has no column to leave empty, so it omits. The rule is about tables.

## Alternatives rejected

**A shared `durable-dashboard` package.** There is nothing to put in it that is not observation: the
projection depends on the port and the DTOs, both already in the component. It would add a satellite
repository to split, publish and version, to hold code whose only dependency is code it would sit
beside.

**Leaving presentation to each host.** The measured cost is the table above: six panels, no two
surfaces agreeing, and four defects none of which is a mistake in either implementation. With two
more surfaces planned, the divergence is not a state, it is a rate.

**Promoting the Sylius model rather than the Magento one.** It was the poorer of the two — no
positioning, no queue-versus-work — and promoting it would have handed Magento a downgrade to obtain
uniformity. The richer implementation goes up; the surface that lacked the panel gains it.

**Normalising what a backend records with an event.** Deliberately not done. `details` carries the
backend's own vocabulary; deciding which of its facts deserve a common name is worth doing once
operators have said what they look for, and is a fabrication before then. The price is that a
payload can hold a value that does not render, which is why `RecordedDetails` exists.

## Consequences

- **`ProcessDetail` goes from eleven methods to seven.** `segments()`, `getEvents()`,
  `actionLabel()` and `formatDetails()` are gone; `getTimeline()` survives as a memoised accessor to
  the shared projection instead of an implementation of it. What stays is `scale()`, which needs a
  column width. `RuntimeFactory` loses `hasCluster()`: ephemerality is a fact the catalogue reports,
  not one the host infers from a missing DSN.
- **The timeline logic has tests for the first time.** Eighteen cases, including three no surface
  covered: a single-event action, a run inside one microsecond, a run still going.
- **Templates are analysed by nothing.** PHPStan and Psalm run against the real Magento classes in
  CI, but neither reads a `.phtml`, and Twig is checked by nobody. Three render suites now execute
  the pages — one per screen, with a block double and a global `__()`, so the two Magento ones need
  neither Magento nor a database. Each was checked by mutation: renaming the property a template
  reads makes them fail.
- **`BackendHealth` gains a parameter with a default**, so no bridge changed.
- **The Magento listing reads its window twice per render** — the banner counts, the provider lists.
  Accepted and commented; the exit, if it costs, is a request-scoped cache around the catalogue, not
  a coupling between the banner and the grid.
- **Filament and API Platform inherit a projection rather than a precedent.** What they must write is
  a chrome, and what they must not re-decide is written down.

## References

- [DUR037 — Run observation is a projection, and an absent fact stays absent](DUR037-run-observation-as-a-projection.md) — settled what a backend is asked; this settles what a surface does with the answer.
- [DUR043 — The projection is a port, and the in-memory backend reads its own runs](DUR043-the-projection-is-a-port-and-in-memory-reads-itself.md) — the in-memory catalogue whose message carried the third state in prose.
- [DUR046 — Magento: a Tier 1 host, and the four things it changed about the core](DUR046-magento-a-tier-1-host-that-improved-the-core.md) — the host whose screen turned out to hold the richer half.
