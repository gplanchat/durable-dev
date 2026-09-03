# workflow-run-observation Specification

## ADDED Requirements

### Requirement: A run has one identifier, whichever backend records it

An execution is named by whoever starts it. That name SHALL be what identifies the run afterwards,
on every backend, and SHALL be carried by the description a catalogue returns.

The identifier SHALL be the name the caller supplied, unaltered — not a value derived from it. A
backend MAY derive its own internal keys from that name; a derivation that cannot be reversed, or
that maps two distinct names onto one, SHALL NOT be presented as the identifier.

The identifier SHALL be fixed for the life of the execution. A backend's own identity for a run MAY
change while the execution continues — a backend that starts a fresh history on continuation gives
that history a new identity — and SHALL remain available as a separate fact, because reading a
history can need it. It SHALL NOT be what the operator, a link, or another system uses to name the
run.

A fact a backend does not have stays absent, as it already does. An identifier is not such a fact:
every backend receives the caller's name at start, so no backend may present it as absent.

#### Scenario: The same run is named the same on two backends

- **WHEN** the same execution name is started on an application backed by a SQL database and on an
  application backed by a workflow cluster
- **THEN** the run's identifier reads identically in both catalogues
- **AND** it reads as the name the caller supplied, not as a sanitised or prefixed form of it

#### Scenario: A run keeps its identifier across a continuation

- **WHEN** an execution continues as new on a backend that starts a fresh history for the
  continuation
- **THEN** the identifier the caller supplied still names the run
- **AND** the backend's own identity for the new history is available as a separate fact

#### Scenario: A name the backend cannot store unaltered is refused at the start

- **WHEN** an execution is started with a name that the backend's own key derivation would alter
- **THEN** the start is refused, naming the execution and saying what about it cannot be stored
- **AND** no execution is created under an altered name

### Requirement: A run is openable by its identifier alone

Given an execution identifier, a catalogue SHALL return that run's description, and SHALL do so
without the caller having to page through the catalogue to find it. An operator SHALL be able to
turn an identifier into a page, and a link to a run SHALL keep resolving after the run has left the
first page, after a filter changes, and after any cursor has expired.

An identifier that names no run SHALL be reported as naming no run. It SHALL NOT resolve to some
other run, and SHALL NOT be indistinguishable from a run that recorded nothing.

#### Scenario: An old run is openable without paging to it

- **WHEN** an operator opens a run whose identifier is older than every run on the first page
- **THEN** the run's description is returned
- **AND** no paging over the catalogue was required to find it

#### Scenario: A link to a run outlives the list it came from

- **WHEN** an operator saves a link to a run and opens it later, after further runs have been
  recorded and under a different filter
- **THEN** the same run is shown

#### Scenario: An unknown identifier is reported as unknown

- **WHEN** a catalogue is asked for an identifier that names no run
- **THEN** it reports that no run bears that identifier
- **AND** it does not return the first run of the catalogue instead

### Requirement: Paging over the catalogue loses no run and repeats none

A cursor is opaque, and what a backend encodes in it is the backend's business. What the component
owes is the property, not the shape: reading a catalogue page by page SHALL yield every run once,
while runs are still being recorded.

A cursor SHALL NOT be readable as, or usable as, anything but a cursor. An operator or an
intermediary that can read a business identifier out of a cursor will come to depend on it, and a
cursor that is a business identifier cannot later become a compound position without breaking them.

A page that is exactly full SHALL NOT offer a next page that turns out to be empty.

#### Scenario: Runs recorded during paging do not shift the window

- **WHEN** a catalogue is read page by page while further runs are being recorded
- **THEN** every run that existed when the first page was read appears exactly once across the pages
- **AND** no run appears twice

#### Scenario: A cursor carries nothing an operator can read

- **WHEN** a page boundary is reached and a cursor is returned
- **THEN** the cursor does not read as a business identifier
- **AND** returning it to the same catalogue yields the following page

#### Scenario: An exactly full page does not promise an empty one

- **WHEN** the last page of a catalogue contains exactly as many runs as were asked for
- **THEN** no next cursor is offered

### Requirement: An empty history means the execution recorded nothing

An empty recorded history SHALL mean one thing: this execution has nothing readable to show. It
SHALL NOT also mean that the description handed to the catalogue was unusable.

A description a catalogue produced SHALL always be readable by that same catalogue. Where a backend
needs more than the identifier to read a history, the catalogue SHALL carry what it needs on the
description it returns, rather than returning nothing when it is missing.

#### Scenario: Every listed run can have its history read

- **WHEN** an operator opens each run of a catalogue page in turn
- **THEN** each run's recorded history is read
- **AND** none reports an empty history on the grounds that its description was incomplete

#### Scenario: A run that recorded nothing is told apart from a run that cannot be read

- **WHEN** a history is read for an execution that recorded no events
- **THEN** an empty history is returned
- **AND** it is not reported as a failure

## MODIFIED Requirements

### Requirement: Runs are observable whichever backend records them

An operator SHALL be able to open the workflow dashboard of an application and see the runs that
application has recorded, whichever backend records them. Seeing them SHALL NOT require installing
the Temporal bridge, and therefore SHALL NOT require `ext-grpc`.

What a catalogue reports about a run SHALL carry the same meaning on every backend. Where two
backends both report a fact, an operator SHALL be able to read it the same way without knowing which
backend answered; where one has a notion the other lacks, that difference SHALL be visible as an
absent fact and never as the same field holding two different kinds of value.

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

#### Scenario: One field does not hold two kinds of value

- **WHEN** the descriptions returned by two backends for comparable runs are read field by field
- **THEN** each field holds the same kind of value on both
- **AND** a field one backend has no notion of is absent there, rather than holding a value of
  another kind
