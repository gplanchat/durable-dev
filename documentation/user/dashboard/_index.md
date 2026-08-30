---
title: The dashboard
weight: 17
---

# The dashboard

There is **one** dashboard. Sylius and Magento render it in their own admin chrome, and two more
surfaces are on the way, but what they show, how a run is grouped, and the words they use are
decided once — in `gplanchat/durable`, beside the observation model the pages read.

That is not tidiness. A panel one surface has and another lacks is a question one application can
answer about a run and another cannot, about the same run, recorded by the same backend. An operator
who works on two applications of the same house should have nothing to translate.

## What every dashboard shows

### 1. The state of the backend

An empty list carries no information on its own: it reads the same when nothing ran, when the
cluster is down, and when the journal cannot outlive the request that renders the page. So the page
says which of the three it is, before showing anything.

| State | What the page says | What to do |
| --- | --- | --- |
| No readable backend is configured | Says so without naming any particular backend — one may never have been involved | Configure one |
| A backend answers | Names it, and when the check was made | Nothing |
| A backend cannot be reached | Names it, so you know what to restart, and dates the check | Restart it |
| A backend answers, and its journal dies with the request | Says an empty list is the correct answer here, not a failure, and what to configure to read across processes | Nothing, or configure a shared backend |

The last one is the in-memory journal under PHP-FPM: the request rendering the dashboard has
executed no workflow, so it sees nothing, and it is right. Hiding that would teach you that nothing
ran at all.

### 2. The runs

Filterable by outcome — running, completed, failed, cancelled, continued as new — and paged.

A **continued-as-new** run is not a failure. It is a normal ending: the component treats it as a
fresh execution, and the run that handed over finished without error. Painting both alike would put
perfectly healthy long-running workflows in red.

### 3. Counters, over what you are looking at

One per outcome, and they cover **the set the list is paging through** — never the application's
whole history. Each surface says which set that is, because it depends on how the host pages:

- the Sylius dashboard fetches a page and counts it;
- the Magento grid pages by offset inside a bounded window, so the set is the window, and the screen
  says so as soon as the window is full.

A heading reading `Total` above a twenty would teach you that an application with five hundred runs
has twenty.

### 4. A run's recorded history — one line per *action*

An action is not an event. An activity scheduled, started and completed is **one action and three
events**; so is a timer, so is a Nexus operation. A timeline ranked by kind — "the activities", "the
signals" — makes you recompose an action from three rows to answer the question you came with: how
long did *that one* take.

So each line is an action, placed in time, and its bar is its duration:

- **The run itself is the first line**, named after the workflow and holding its workflow tasks. A
  child workflow keeps a line of its own; so does a signal received and an update handled.
- **The bar is cut between consecutive events.** Without that, once the run occupies a line its bar
  covers the whole run and says "the run took as long as the run" — and the twenty-two seconds spent
  waiting for a worker, the only interesting fact, disappear inside it.
- **A hatched interval is a queue, not work.** Time spent waiting for someone to pick the task up
  and time spent doing it draw the same rectangle, and the first question in front of a slow run is
  which of the two you are looking at: your code, or nobody at the other end.
- **Position comes from the recorded time, not from rank.** That is what makes a run that spent
  twenty-two of its twenty-four seconds waiting look like one. Events inside the same second are
  still told apart, so a run shorter than a second is a timeline rather than a stack.
- **Red marks the event that went wrong, not the action.** An activity that failed twice and
  succeeded on the third try carries red and ends well. A cancellation is not painted red: it is an
  outcome somebody asked for, not a breakdown.
- **Every row names its action.** Only the event that opens an action carries the name of the
  activity, the child workflow or the operation — the ones that follow carry a number. A journal
  showing each event's own label would hide, on two rows out of three, the very name you are looking
  for. A timer has no business name at all, so its delay names it.

Each event unfolds onto **what the backend recorded with it**: the arguments an activity was called
with, what it returned, the class and message of a failure. That content is the backend's own
vocabulary and is deliberately not normalised — deciding which of a backend's facts deserve a common
name is worth doing once operators have said what they look for, and is a fabrication before then.
An event the backend recorded nothing with stays a plain line rather than an expander onto nothing.

## A fact a backend does not have is shown as absent

Two absences look alike and are not:

- **The backend has no such notion** — a task queue on a backend that has none, a grouping across
  continuations on a backend that records none. Nothing is shown, and no column is offered either:
  an empty column would teach you that *this run* has no queue, when it is the backend that has no
  queues.
- **This run does not have this fact** — a run still going has no end date. The column exists for
  its neighbours, so in a table it reads as an explicit em dash. A blank cell reads as a rendering
  that failed.

## What differs between hosts

The chrome, and only the chrome.

| | Sylius | Magento |
| --- | --- | --- |
| Where | Admin menu → Durable | **System > Durable processes > Process history** |
| The list | Tabler cards, cursor paging | The standard grid — paging, bookmarks, column controls, export, and a status filter whose options come from the status enum |
| Paging | Cursor, 20 a page | Offset inside a 200-run window, whose ceiling the screen states |
| Read-only | Yes | Yes |

Both are **read-only**, and will stay so: what you come to a dashboard for is to know whether an
order went through, not to restart it by hand. Resuming an execution from a browser would bypass the
per-execution lock.

Scaling seconds into a bar width is the one presentation decision a host owns — it needs to know
how wide its column is, and a surface that renders no markup has none. Everything else is shared.

## See also

- [Packages](../packages/) — `gplanchat/durable-plugin` for the Sylius chrome,
  `gplanchat/durable-magento` for the Magento one
- [Backends](../backends/) — which of them records what
