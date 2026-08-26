## Purpose

What an operator can see about the runs an application has recorded: which runs exist, what became
of each, what its recorded history looks like, and what the dashboard does about facts the backend
in use cannot supply.

## ADDED Requirements

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
- **AND** the counters above the list agree with what the list shows

### Requirement: Reading a run's recorded history

An operator SHALL be able to select a run and see the history recorded for it: its events in
recorded order, and a timeline of the operations the run performed.

#### Scenario: Selecting a run shows its events

- **WHEN** an operator selects a run in the list
- **THEN** the events recorded for that run are shown in the order they were recorded
- **AND** each event carries the time it was recorded

#### Scenario: The timeline separates kinds of operation

- **WHEN** an operator selects a run that scheduled activities and received signals
- **THEN** the timeline shows the activities and the signals on distinct lanes
- **AND** an activity lane is labelled with the name of the activity, not with an internal
  identifier

#### Scenario: A run whose history cannot be read

- **WHEN** the history of the selected run cannot be read
- **THEN** the run stays in the list with the description it already had
- **AND** the page states that its history is unavailable rather than showing an empty timeline

### Requirement: A fact a backend does not have is shown as absent

The dashboard SHALL present a fact the backend in use does not have as absent, never as an empty or
placeholder value. An operator SHALL be able to tell "this backend has no such concept" from "this
run has an empty value".

#### Scenario: A backend without task queues

- **WHEN** an operator opens the dashboard of an application whose backend has no per-run task
  queue
- **THEN** no task queue is shown for its runs
- **AND** no empty task queue column is shown either

#### Scenario: A backend that records no queries

- **WHEN** an operator selects a run on a backend that records no queries
- **THEN** the timeline offers no query lane to display
- **AND** the absence is not presented as a run that received no query

#### Scenario: Backend health is reported for whichever backend is in use

- **WHEN** an operator opens the dashboard and the backend in use cannot be reached
- **THEN** the page reports that the backend is unreachable, naming the backend in use
- **AND** it reports when the check was made
