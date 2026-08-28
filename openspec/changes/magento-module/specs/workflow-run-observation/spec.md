# workflow-run-observation Specification

## MODIFIED Requirements

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
