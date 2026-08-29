# workflow-run-observation Specification

## ADDED Requirements

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

Positioning, grouping into actions, telling a queue apart from work, and saying how long something
took are **not** the host's business. They are decided once, from the recorded history, and every
surface SHALL render that same result — otherwise the same run reads as two different runs to an
operator who works on two applications of the same house.

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

### Requirement: A recorded detail that cannot be rendered degrades on every surface

What a backend recorded with an event is the backend's own vocabulary, and a journal CAN hold a
payload that does not survive being rendered — a byte string that is not valid text, a value the
surface's encoder rejects. A surface SHALL degrade to the line it would have shown without the
detail, on every host.

An event whose detail cannot be rendered SHALL NOT prevent the rest of the history from being read.
It is the event the operator came to look at that is most likely to hold the unusual payload.

#### Scenario: An unrenderable payload does not break the screen

- **WHEN** an operator opens a run in which one event carries a payload the surface cannot render
- **THEN** the remaining events are shown with their details
- **AND** the offending event is shown as a line, without an expander
- **AND** the screen is not replaced by an error

## MODIFIED Requirements

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
