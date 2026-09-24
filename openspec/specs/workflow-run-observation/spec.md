# workflow-run-observation Specification

## Purpose
What an operator can see about the runs an application has recorded: which runs exist, what became
of each, what its recorded history looks like, and what the dashboard does about facts the backend
in use cannot supply.
## Requirements
### Requirement: Runs are observable whichever backend records them

An operator SHALL be able to open the workflow dashboard of an application and see the runs that
application has recorded, whichever backend records them. Seeing them SHALL NOT require installing
the Temporal bridge, and therefore SHALL NOT require `ext-grpc`.

#### Scenario: A DBAL-backed application lists its runs

- **WHEN** an operator opens the dashboard of an application whose durable executions are recorded
  in a SQL database
- **AND** the Temporal bridge is not installed
- **THEN** the page lists the runs that application has recorded
- **AND** it does not report that Temporal is unreachable

#### Scenario: A Temporal-backed application is unaffected

- **WHEN** an operator opens the dashboard of an application backed by Temporal
- **THEN** the page lists the same runs, with the same names, statuses and history, as before this
  change

#### Scenario: No backend can answer

- **WHEN** an operator opens the dashboard of an application that records durable executions
  nowhere the dashboard can read
- **THEN** the page states that no readable backend is configured
- **AND** it does not name Temporal, which may never have been involved

### Requirement: A run stays describable after it ends badly

A run SHALL remain describable — named, dated, and carrying its outcome — after it has failed,
after it has been cancelled, and after it has continued as new. Its description SHALL NOT depend on
records that the end of the run removes.

#### Scenario: A failed run is named

- **WHEN** a workflow fails on a DBAL-backed application
- **AND** an operator opens the dashboard
- **THEN** the run appears with the name of the workflow that failed
- **AND** its status reads as failed

#### Scenario: A cancelled run is named

- **WHEN** a workflow is cancelled on a DBAL-backed application
- **AND** an operator opens the dashboard
- **THEN** the run appears with the name of the workflow that was cancelled
- **AND** its status distinguishes cancellation from failure

#### Scenario: A run that continued as new leaves both runs visible

- **WHEN** a workflow continues as new on a DBAL-backed application
- **THEN** the run that ended and the run that took over both appear in the list
- **AND** the run that ended is not reported as failed
- **AND** on a backend that does not record the link between them, they appear as two independent
  runs rather than one presented as a continuation of the other

#### Scenario: Filtering by status finds the failures

- **WHEN** an operator filters the run list by failed status
- **THEN** the list shows the runs that failed and no others

### Requirement: Reading a run's recorded history

An operator SHALL be able to select a run and see the history recorded for it: its events in
recorded order, a timeline of the **actions** the run performed, and — for each event — what the
backend recorded with it.

An action is not an event. An activity scheduled, started and completed is one action and three
events; so is a timer, so is a Nexus operation. A timeline that ranks events by kind makes the
operator recompose an action from three rows to answer the question they came with — how long did
*that one* take. The observation model SHALL therefore carry, for each event, the action it belongs
to, and SHALL say plainly when an event is an action on its own rather than leaving the surface to
guess.

A line answers *what happened*. The next question an operator asks, every time, is *with what* —
the input an activity was called with, the value it returned, the class and message of a failure.
An event model that carries only a label cannot answer it, and a surface built over such a model
offers an expander that opens onto nothing.

A duration is not one fact but two. Time spent waiting for someone to pick the work up and time
spent doing the work draw the same rectangle, and the first question an operator asks of a slow run
is which of the two they are looking at — their own code, or nobody at the other end. The
observation model SHALL therefore say, for each event, whether the work begins there, so that an
interval ending on such an event can be shown as a queue rather than as work.

Not every event names what it belongs to. Only the event that opens an action carries the name of
the activity, the child workflow or the operation; the ones that follow carry a number. A history
that shows each event's own label therefore hides, on two rows out of three, the very name the
operator is looking for. Each event SHALL be presented alongside the name of the action it belongs
to. A timer has no business name at all — its delay is the only fact it carries, and that delay
SHALL be what names it, rather than the class of the event that started it.

What went wrong SHALL be distinguishable at a glance, on the event and not on the action: an
activity that failed twice and succeeded on the third try both carries a failure and ends well. A
cancellation and a termination SHALL NOT be presented as failures — they are outcomes somebody
asked for, and presenting both alike leaves the distinction meaning nothing.

What the event carries SHALL be the backend's own vocabulary. Normalising it would mean deciding,
for every backend, which of its facts deserve a common name — a decision worth making once
operators have said what they look for, and a fabrication before then.

#### Scenario: Selecting a run shows its events

- **WHEN** an operator selects a run in the list
- **THEN** the events recorded for that run are shown in the order they were recorded
- **AND** each event carries the time it was recorded

#### Scenario: An event unfolds onto what it recorded

- **WHEN** an operator opens an event that scheduled an activity
- **THEN** the arguments the activity was called with are shown
- **AND** they are shown decoded, not in the transport encoding the backend stores them in
- **AND** an event the backend recorded nothing with stays a single line rather than an empty
  expander

#### Scenario: The timeline has one line per action, not per kind

- **WHEN** an operator selects a run that scheduled an activity, which was then started and
  completed
- **THEN** the timeline shows those three events on a single line
- **AND** that line is labelled with the name of the activity, not with an internal identifier
- **AND** the line shows how long the action took, so the operator does not have to subtract two
  timestamps read off two rows
- **AND** two activities of the same name scheduled twice are two lines, not one

#### Scenario: The run itself is the first line, and its children are not part of it

- **WHEN** an operator selects a run that ran workflow tasks and started a child workflow
- **THEN** the events of the run itself — its start, its workflow tasks, its end — are on a single
  line, the first
- **AND** that line is labelled with the name of the workflow, not with the name of an event
- **AND** the child workflow has a line of its own, labelled with the child's workflow type
- **AND** a signal received and an update handled are lines of their own, not folded into the run's
- **AND** an interval during which the run recorded nothing — waiting for a worker, waiting for a
  reply — is still readable inside its line, and says how long it lasted

#### Scenario: An event that is an action on its own

- **WHEN** an event has no action to belong to, such as the start of the execution or a signal
- **THEN** it occupies a line of its own
- **AND** it is not merged into a line with events it has nothing to do with

#### Scenario: The timeline shows waiting, not just order

- **WHEN** an operator selects a run in which nothing happened for most of its duration
- **THEN** the events are positioned by the time they were recorded, not spread evenly by rank
- **AND** the interval during which nothing was recorded is visible as such
- **AND** events recorded within the same second are told apart, so a run shorter than a second
  is a timeline rather than a single stack

#### Scenario: Waiting to be picked up is not shown as work

- **WHEN** an operator selects a run in which a task was scheduled and only picked up by a worker
  twenty seconds later
- **THEN** the interval between the scheduling and the pick-up is distinguished from the intervals
  during which the work was actually running
- **AND** it says how long the wait lasted, and that it was a wait
- **AND** an interval is not exaggerated to make it visible: a four-millisecond queue does not draw
  wider than six milliseconds of work

#### Scenario: Every row of an action names the action

- **WHEN** an operator reads the recorded history of a run that scheduled, started and completed an
  activity
- **THEN** all three rows name that activity, and not the class of each event
- **AND** the name shown is the same string that labels the action in the timeline, so a row of one
  is findable in the other
- **AND** a timer announces the delay it was set for, without the operator subtracting two
  timestamps read off two rows

#### Scenario: What failed is told apart from what was cancelled

- **WHEN** an operator reads the history of a run in which an activity failed and another was
  cancelled
- **THEN** the failure is distinguished from the events that went well
- **AND** the cancellation is not distinguished as a failure
- **AND** a failure inside an action that ended well is still shown as a failure, on its own event
- **AND** a cancellation that could not be delivered is a failure, because the run it targeted is
  still going

#### Scenario: A run whose history cannot be read

- **WHEN** the history of the selected run cannot be read
- **THEN** the run stays in the list with the description it already had
- **AND** the page states that its history is unavailable rather than showing an empty timeline

### Requirement: A fact a backend does not have is shown as absent

The dashboard SHALL present a fact the backend in use does not have as absent, never as an empty or
placeholder value. An operator SHALL be able to tell "this backend has no such concept" from "this
run has an empty value".

Two absences that look alike SHALL be treated differently, and telling them apart is the whole point
of the requirement:

- **The backend has no such notion.** A task queue on a backend that has none, a grouping across
  continuations on a backend that does not record one. Nothing is shown, and no column, field or
  lane is offered either — an empty column would teach the operator that this run has no queue,
  when it is the backend that has no queues.
- **The run does not have this fact yet, or will never have it.** A run still going has no end date;
  a backend that records end dates recorded none for it. Here the fact belongs to the model and the
  run is one case among others, so the field exists and SHALL be rendered as explicitly empty. In a
  fixed-column layout an em dash is that rendering: it says "nothing here", where a blank cell reads
  as a rendering that failed.

#### Scenario: A backend without task queues

- **WHEN** an operator opens the dashboard of an application whose backend has no per-run task
  queue
- **THEN** no task queue is shown for its runs
- **AND** no empty task queue column is shown either

#### Scenario: A backend that records no queries

- **WHEN** an operator selects a run on a backend that records no queries
- **THEN** no query is shown in its history
- **AND** the absence is not presented as a run that received no query

#### Scenario: A run that has not ended shows no end date

- **WHEN** an operator reads a list in which one run is still going
- **THEN** the run appears on the same columns as the others
- **AND** its end date reads as explicitly empty rather than as a blank cell
- **AND** the column is not removed for the other runs, which have ended

#### Scenario: Backend health is reported for whichever backend is in use

- **WHEN** an operator opens the dashboard and the backend in use cannot be reached
- **THEN** the page reports that the backend is unreachable, naming the backend in use
- **AND** it reports when the check was made

### Requirement: Every surface shows the same panels

Whichever host renders a Durable dashboard, an operator SHALL find the same panels answering the
same questions. A panel present on one surface and missing from another is not a difference of
chrome: it is a question one application can answer about a run and another cannot, about the same
run, recorded by the same backend.

The panels are: the state of the backend; a list of the runs recorded, filterable by outcome and
paged; counters over what the list shows; and, for a selected run, its recorded history as actions
positioned in time, each event unfoldable onto what the backend recorded with it.

How a panel is drawn is the host's business. A surface with no markup at all SHALL be able to serve
the same panels as structured data, so the contract SHALL NOT require a layout, a stylesheet or a
templating language.

Grouping events into actions, ordering them, telling a queue interval apart from a working one, and
saying how long something took are **not** the host's business. They are decided once, from the
recorded history, and every surface SHALL render that same result — otherwise the same run reads as
two different runs to an operator who works on two applications of the same house.

What is decided once SHALL be expressed as **times and durations**, not as a drawing: an offset from
the run's first recorded event and a length, in seconds. Turning those into a width, a column or a
row is the host's business, and a surface that draws nothing at all SHALL still be able to use them.
The rule that a very short interval is not exaggerated to make it visible therefore binds whoever
turns a duration into a length, and does not oblige the shared projection to know about lengths at
all.

#### Scenario: The same run reads the same on two hosts

- **WHEN** the same recorded run is opened on two applications running different hosts
- **THEN** its actions are grouped the same way and labelled with the same strings
- **AND** an interval spent waiting to be picked up is shown as a wait on both
- **AND** a duration is worded identically on both, rather than in seconds on one and milliseconds
  on the other

#### Scenario: A surface with no markup serves the same panels

- **WHEN** a surface that renders no HTML exposes the dashboard
- **THEN** it can serve every panel the other surfaces show
- **AND** it does so without a templating language

#### Scenario: A host renders the panels in its own chrome

- **WHEN** a host has a standard listing component its operators already know
- **THEN** the run list may be rendered with it
- **AND** the panels it must show are unchanged by that choice

### Requirement: A surface reports the state of the backend it reads

Every surface SHALL report which backend it is reading and what state that backend is in, before
showing an empty list. An empty list carries no information on its own: it reads the same when
nothing ran, when the cluster is down, and when the journal cannot outlive the request.

Three states SHALL be distinguishable, and an operator SHALL be able to tell which one they are
looking at without reading the source:

- **No readable backend is configured.** Stated without naming any particular backend, which may
  never have been involved in this application.
- **A backend is configured and cannot be reached.** Named, so the operator knows what to go and
  restart, and dated, so they know when the check was made.
- **A backend answers, and its journal cannot outlive the request that renders the page.** The list
  is empty and empty is the correct answer, not a failure — the request that renders the dashboard
  is not the process that executed anything. This state SHALL say what to configure to read across
  processes.

#### Scenario: An unreachable backend is not shown as an empty list

- **WHEN** an operator opens the run list of an application whose backend cannot be reached
- **THEN** the page reports that the backend is unreachable, naming it
- **AND** it does not present an empty list as though nothing had run

#### Scenario: A journal that dies with the request says so

- **WHEN** an operator opens the run list of an application whose journal lives only in the process
  that writes it
- **THEN** the page states that an empty list is the expected answer rather than a failure
- **AND** it states what to configure so that runs from other processes become readable
- **AND** it does not report the backend as unreachable, because it answered

#### Scenario: Health is reported on the list, not only on a detail screen

- **WHEN** an operator opens the run list and never selects a run
- **THEN** the state of the backend is already reported on that screen

### Requirement: Counters count what the operator is looking at

Counters shown above a run list SHALL count the runs on the page in front of the operator, and
SHALL be labelled as counting that. They SHALL NOT be presented as counting everything the
application has ever recorded.

A count over the whole history is a different question, answered by a different query, and a
counter that silently answers the second question under the first teaches an operator that an
application with five hundred runs has twenty.

#### Scenario: The counters agree with the list

- **WHEN** an operator filters a run list and reads the counters above it
- **THEN** the counters sum to the number of runs the list shows
- **AND** every outcome has a counter, so the counters sum to the total shown

#### Scenario: The counters do not claim to cover the whole history

- **WHEN** an application has recorded more runs than one page holds
- **THEN** the counters are labelled as counting the current page
- **AND** no counter is labelled as a total over the application's history

### Requirement: A run that is listed can be opened

A run a surface lists SHALL be openable from that list, and its recorded history SHALL be readable.
Where a surface pages over a bounded window rather than over the whole catalogue, that ceiling SHALL
be stated to the operator rather than left to be discovered by a run that will not open.

#### Scenario: Opening a run from any page of the list

- **WHEN** an operator opens a run from a page of the list that is not the first
- **THEN** the run's recorded history is shown
- **AND** it is not reported as unknown

#### Scenario: A bounded window states its bound

- **WHEN** a surface can only page within a bounded window of the most recent runs
- **THEN** the operator is told that older runs are beyond what this screen reads
- **AND** the list does not offer a run it cannot open

### Requirement: One bad value does not take a recorded payload down with it

What a backend recorded with an event is the backend's own vocabulary, and a journal CAN hold a
value that does not survive being rendered — a byte string that is not valid text, a handle, a
structure deeper than the encoder walks. What surrounds that value is ordinary and is what the
operator came for. A surface SHALL therefore show the payload it can render and present the value it
cannot as absent, rather than losing the whole payload to it.

Every host SHALL degrade the same way. Where a payload cannot be rendered at all, the event SHALL be
shown as a line without an expander — an expander that opens onto nothing sends the operator to open
it again.

#### Scenario: A payload holding one bad value still opens

- **WHEN** an operator opens a run in which one event carries a payload holding a value the encoder
  cannot represent
- **THEN** the rest of that payload is shown
- **AND** the value that could not be rendered is shown as absent
- **AND** the screen is not replaced by an error

#### Scenario: An event with nothing recorded offers nothing to open

- **WHEN** an operator reads a history in which some events carry nothing
- **THEN** those events are shown as plain lines
- **AND** they offer no expander to open

