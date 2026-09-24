## Why

Two hosts render a Durable dashboard today — Sylius through `gplanchat/durable-plugin`, Magento
through `gplanchat/durable-magento` — and two more are planned: a Filament panel and an API Platform
surface. Each was written on its own, and each answers the operator's questions with a different
half of the same model.

| Panel | Sylius | Magento |
|---|---|---|
| Backend health, named, with the time of the check | yes | **never probed** |
| Journal that lives and dies with the request, said out loud | absent | yes |
| Counters per outcome | yes | absent |
| Filter by outcome | yes | yes, on the standard grid |
| Paging | cursor, 20 a page | offset inside a fixed window |
| One line per action | yes, as stacked blocks | yes, **positioned in time** |
| Queue told apart from work | **absent** | yes, per interval |
| Recorded details unfolded | yes | yes |

Neither surface is the poorer one throughout, which is what makes this worth settling rather than
copying. Sylius alone probes the backend and counts outcomes; Magento alone positions actions in
time, tells a queue apart from work, and says when the journal cannot outlive the request. An
operator moving between two applications of the same house reads the same run twice and learns
different things about it — and the two surfaces still to be written have no source of truth to
follow but whichever one their author happens to open first.

The observation model is not the problem. `WorkflowRunEvent` already carries `actionKey`, `started`,
`failed` and `details`, and `ReadableDuration` already sits beside it precisely so that a duration
reads the same everywhere. What is missing is the layer above: **the projection from that model to
what a surface shows**, and the statement that every host owes the same panels.

## What Changes

- The timeline projection — actions positioned in time, one segment per interval, a queue interval
  told apart from a working one, a mark per event — SHALL live **once**, beside the observation
  model, and every surface SHALL render that projection rather than derive its own. It exists today
  only inside Magento's detail block.
- Every surface SHALL report backend health, whichever host renders it. Magento's list does not,
  which is the failure the health probe was introduced to remove: an unreachable cluster renders a
  serene empty grid, and the operator concludes there is nothing to see.
- A dashboard whose journal **cannot outlive the request that renders it** SHALL say so. This is a
  third backend state, distinct from *reachable* and from *no readable backend*: the backend answers
  correctly, and the correct answer is empty. Only Magento says it today, and only in prose.
- Counters SHALL be stated to count **the page in front of the operator**, not the application's
  whole history — which is what they already do, unlabelled, under a heading that reads `Total`.
- A run the surface lists SHALL be openable. Magento's list slices a fixed window and its detail
  screen scans another; a run past either is listed and cannot be opened, or is not listed at all.
  Where paging has a ceiling, the ceiling SHALL be stated rather than left to be discovered.
- **The em dash is settled as the rendering of a fact that is absent for *this run*** — a run still
  running has no end date — while a fact the **backend** has no notion of keeps its existing
  treatment: no column at all. The shipped requirement forbids placeholders without drawing that
  line, and both surfaces already need both behaviours.
- **BREAKING** no. Nothing an application declares changes shape; the contract added here is between
  the component and the surfaces it ships.

### The contract is data, not markup

API Platform has no Twig, no phtml and no CSS. Filament has neither. So what is settled here is the
**projection and the panel inventory** — what a surface must be able to say and what it computes
from — never a layout. A host renders it in its own chrome: Magento on the standard UI grid its
operators already filter, Sylius on Tabler cards, API Platform as JSON.

### Where the projection lives

Beside the observation model, in the component, not in a shared "dashboard" package. The precedent
is already in place and was argued the same way: `ReadableDuration` is formatting, it lives in
`Gplanchat\Durable\Observation`, and it does so because a threshold that must read the same on every
host is decided once. A timeline projection is the same kind of fact at a larger size. The change
leaves an ADR behind saying so.

### Not in scope

- **Normalising what a backend records with an event.** `details` carries the backend's own
  vocabulary on purpose; deciding which of its facts deserve a common name is worth doing once
  operators have said what they look for, and is a fabrication before then.
- **Acting on a run from a dashboard.** Every surface here is read-only, and stays so: resuming an
  execution from a browser would bypass the per-execution lock.
- **The Symfony profiler collector.** It traces one request; it does not read the run catalogue. It
  is not a third dashboard.
