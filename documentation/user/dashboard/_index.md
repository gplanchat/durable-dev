---
title: The dashboard
weight: 17
---

# The dashboard

There is **one** dashboard. Sylius, Magento and Filament render it in their own admin chrome, and an
API Platform surface is on the way. What they show and how a run is grouped are decided once, in
`gplanchat/durable`, beside the observation model the pages read. The Symfony web profiler panel
reads the same journals but answers a narrower question, what happened during one request, so it
keeps its own layout. [Parity](parity/) lists, row by row, what each surface shows today and where
they differ.

The point is not consistency for its own sake. A panel one surface has and another lacks is a question one application can
answer about a run and another cannot, about the same run, recorded by the same backend. An operator
who works on two applications of the same house should have nothing to translate.

## What every dashboard shows

### 1. The state of the backend

An empty list carries no information on its own: it reads the same when nothing ran, when the
cluster is down, and when the journal cannot outlive the request that renders the page. So the page
says which of the three it is, before showing anything.

| State | What the page says | What to do |
| --- | --- | --- |
| No readable backend is configured | Says so without naming any particular backend, since one may never have been involved | Configure one |
| A backend answers | Names it, and when the check was made | Nothing |
| A backend cannot be reached | Names it, so you know what to restart, and dates the check | Restart it |
| A backend answers, and its journal dies with the request | Says an empty list is the correct answer here, not a failure, and what to configure to read across processes | Nothing, or configure a shared backend |

The last one is the in-memory journal under PHP-FPM: the request rendering the dashboard has
executed no workflow, so it sees nothing, and it is right. Hiding that would teach you that nothing
ran at all.

A cluster that answers can still have **no worker** on a role's queue. Nothing fails then: an
execution stops at its first task of that kind. On Temporal, `bin/console durable:health` exits
non-zero when a role's queue has gone two minutes without a poll, and names the
`durable:worker --role` to start; alert on it. It checks workflow and activity when Temporal holds
the journal, and nexus once the application serves a Nexus handler. On Temporal, the Sylius
dashboard shows the same state above the run list, one line per role. The Magento page does too,
for the journal and activity roles.

### 2. The runs

Sylius and Magento filter by outcome (running, completed, failed, cancelled, continued as new);
Filament and the profiler do not. Every list except the profiler's is paged.

On Sylius and Filament, the list can also be filtered by workflow name (the whole name) and by the
start of the execution id. Both are exact about case and take `%` and `_` literally. They show only
where the backend can apply them. On Temporal, that means [turning on its search
attributes](../backends/#register-durables-search-attributes); without them, the page filters by
outcome only. The Magento grid offers the same two filters, and one on the backend run id that follows the rule of the execution id. It applies them to the runs of its window, so they show whatever the backend.

A **continued-as-new** run is not a failure. It is a normal ending: the component treats it as a
fresh execution, and the run that handed over finished without error. Painting both alike would put
perfectly healthy long-running workflows in red.

A running run that **no worker has picked up yet** says so, and since when:
`waiting for a worker · 42 s`. It was dispatched, and nothing consumed it, which means the queue has no worker, or its worker is stopped: start one with [`durable:worker`](../getting-started/#5-run-a-consumer-or-nothing-happens)
on Symfony, or `php artisan queue:work` on Laravel.
A running run without that line has been picked up, and is working or waiting on a timer or a signal. On Sylius and Filament, the counters add a **Waiting for a worker** count over the
same page.

The SQL backends can tell (DBAL on Symfony, Illuminate on Laravel), on a runs table that has the
`picked_up_at` column, and so can the in-memory backend within its process. On Laravel, the
[Filament panel](filament/) is the run list. Temporal cannot from the run list, so neither the
line nor the count appears there; Temporal UI shows pending tasks. Neither does the
Magento grid, whose backends are the in-memory one, empty from the admin, and Temporal. See
[Upgrading](https://github.com/gplanchat/durable-dev/blob/main/UPGRADE.md) to add the column to a
table created before it existed.

After the first pickup, the journal backends record each wait for a worker as events, as Temporal
does: `WorkflowTaskScheduled` when a resume is dispatched, `WorkflowTaskStarted` when a worker takes
it, `WorkflowTaskCompleted` when the pass ends. On the run's line, the interval from scheduled to
started is the hatched wait described under section 4. The first task of a run has no
`WorkflowTaskScheduled`: the application dispatches it through `dispatchNewWorkflowRun()`, which
writes nothing to the journal, and the `waiting for a worker` line above covers that first wait.

A running run also says **what it waits on**, as of its last suspension:
`waiting on timer "grace period" due at 2026-09-24T10:00:00+00:00`,
`waiting on activity charge attempt 2 in flight`, or `waiting on condition at src/…/OrderWorkflow.php:42`.
A signal wait is a condition: the line names where the condition is written, unless the workflow gave
it a label (`await(…, label: 'signal approve')` shows `waiting on signal approve`, see
[Workflows](../workflows/#waiting-on-a-condition)). The same
backends tell it, on a runs table that has the `waiting_on` column. Temporal tells it too, the
Magento grid included, from a `durableWaitingOn` memo the worker updates at each suspension. It
leaves out the attempt, since no workflow task runs when an activity attempt starts, and a timer's
summary, which is never sent to the server. Sylius and Filament show the line in the list and on the run page. Magento and the profiler print the reason without the `waiting on` prefix.

### 3. Counters, over what you are looking at

One per outcome, and they cover **the set the list is paging through**, never the application's
whole history. Each surface says which set that is, because it depends on how the host pages:

- the Sylius and Filament dashboards fetch a page and count it, and the heading gives the number of
  runs on it;
- the Magento grid pages by offset inside a bounded window of 200 runs. Its counters cover that
  window whatever the grid filters say, and the screen says so as soon as the window is full.

A heading reading `Total` above a twenty would teach you that an application with five hundred runs
has twenty. The Magento counters head their first column `In the window`, and the heading above them says that the grid filters do not change them.
The profiler has no counters, since it lists only the runs of one request.

### 4. A run's recorded history, one line per *action* {#4-a-runs-recorded-history--one-line-per-action}

An action is not an event. An activity scheduled, started and completed is **one action and three
events**; so is a timer, so is a Nexus operation. A timeline ranked by kind, "the activities" then "the
signals", makes you recompose an action from three rows to answer the question you came with: how
long did *that one* take.

So each line is an action, placed in time, and its bar is its duration:

- **The run itself is the first line**, named after the workflow and holding its workflow tasks. A
  child workflow keeps a line of its own; so does a signal received and an update handled.
- **The bar is cut between consecutive events.** Without that, once the run occupies a line its bar
  covers the whole run and says "the run took as long as the run", and the twenty-two seconds spent
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
  activity, the child workflow or the operation; the ones that follow carry a number. A journal
  showing each event's own label would hide, on two rows out of three, the very name you are looking
  for. A timer has no business name at all, so its delay names it.

Each event unfolds onto **what the backend recorded with it**: the arguments an activity was called
with, what it returned, the class and message of a failure. That content is the backend's own
vocabulary and is deliberately not normalised, because deciding which of a backend's facts deserve a common
name is worth doing once operators have said what they look for, and is a fabrication before then.
An event the backend recorded nothing with stays a plain line rather than an expander onto nothing.

What unfolds is masked the way `durable:execution:diagnose` and the web profiler mask it: values under
keys such as `password`, `token`, `secret`, `authorization`, `card` or `api_key` are replaced, and long
strings truncated. Anyone with access to the admin can open a run, so this page has no raw view. The
masking goes by key name, so personal data under other keys still shows. The run page masks with the
same redactor as those two: on the Sylius plugin, the service an application aliases to
`Gplanchat\Durable\Observation\PayloadRedactorInterface`; on Magento, a preference the application
declares for that interface; on Filament, what the application binds that interface to in its
container.

## A fact a backend does not have is shown as absent

Two absences look alike and are not:

- **The backend has no such notion.** A task queue on a backend that has none, a grouping across
  continuations on a backend that records none. Nothing is shown, and no column is offered either:
  an empty column would teach you that *this run* has no queue, when it is the backend that has no
  queues.
- **This run does not have this fact.** A run still going has no end date. The column exists for
  its neighbours, so in a table it reads as an explicit em dash. A blank cell reads as a rendering
  that failed. Every surface applies this rule.

## What differs between surfaces {#what-differs-between-hosts}

| | Sylius | Magento | Filament | Web profiler |
| --- | --- | --- | --- | --- |
| Where | **Configuration > Durable Dashboard** | **System > Durable processes > Process history** | Panel navigation > **Durable runs** | The **Durable** panel of the profiler, and its toolbar item |
| What it lists | Every run the backend knows | The 200 most recent runs | Every run the backend knows | The runs dispatched during one request |
| Paging | Cursor, 20 a page | Offset inside the 200-run window, whose ceiling the screen states | Cursor, 20 a page | None, 500 events per journal at most |
| Read-only | Yes | Yes | Yes | Yes |

The Sylius list is a Sylius grid, the Magento one the standard admin grid (paging, column controls,
filters), and the Filament one a table in the panel's own components: on Filament 3 a table reads an
Eloquent query and nothing else, and a catalog's cursor only goes forward. It renders the same on
Filament 3 and 4.

All four are **read-only**: what you come to a dashboard for is to know whether an
order went through, not to restart it by hand. Resuming an execution from a browser would bypass the
per-execution lock.

Scaling seconds into a bar width is one presentation decision a host owns, because it needs to know
how wide its column is, and a surface that renders no markup has none. The words are the same on all four: Outcome, History and Execution, and Backend run on the three
dashboards. The other differences are gaps rather than choices, and [Parity](parity/) lists them.

## See also

- [Parity](parity/) compares the four surfaces, row by row
- [Read a run](reading-a-run/) explains a run page, whichever surface shows it
- [A run does not progress](run-not-progressing/) goes from what you see to what to do
- One page per surface: [Sylius](sylius/), [Magento](magento/), [Filament](filament/),
  [web profiler](profiler/)
- [Packages](../packages/) covers `gplanchat/durable-plugin` for the Sylius chrome,
  `gplanchat/durable-magento` for the Magento one, `gplanchat/durable-filament` for the Filament one
- [Backends](../backends/) says which of them records what
