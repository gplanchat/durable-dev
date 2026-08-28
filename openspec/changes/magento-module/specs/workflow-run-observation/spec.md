# workflow-run-observation Specification

## MODIFIED Requirements

### Requirement: Reading a run's recorded history

An operator SHALL be able to select a run and see the history recorded for it: its events in
recorded order, a timeline of the operations the run performed, and — for each event — what the
backend recorded with it.

A line answers *what happened*. The next question an operator asks, every time, is *with what* —
the input an activity was called with, the value it returned, the class and message of a failure.
An event model that carries only a label cannot answer it, and a surface built over such a model
offers an expander that opens onto nothing.

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

#### Scenario: The timeline separates kinds of operation

- **WHEN** an operator selects a run that scheduled activities and received signals
- **THEN** the timeline shows the activities and the signals on distinct lanes
- **AND** an activity lane is labelled with the name of the activity, not with an internal
  identifier

#### Scenario: The timeline shows waiting, not just order

- **WHEN** an operator selects a run in which nothing happened for most of its duration
- **THEN** the events are positioned by the time they were recorded, not spread evenly by rank
- **AND** the interval during which nothing was recorded is visible as such
- **AND** events recorded within the same second are told apart, so a run shorter than a second
  is a timeline rather than a single stack

#### Scenario: A run whose history cannot be read

- **WHEN** the history of the selected run cannot be read
- **THEN** the run stays in the list with the description it already had
- **AND** the page states that its history is unavailable rather than showing an empty timeline
