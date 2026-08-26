# workflow-deadlines Specification

## Purpose
Bounding a wait in time from workflow code: what the workflow observes when a deadline elapses, how
that verdict survives replay, what happens to the work the deadline was bounding, and how a signal
wait takes a deadline.
## Requirements
### Requirement: Awaiting under a deadline

A workflow SHALL be able to await work under a deadline. If the work settles first, the awaited
value SHALL be returned unchanged. If the deadline elapses first, the await SHALL fail with a
timeout failure.

The timeout failure SHALL be distinguishable from every value the awaited work could have returned,
and SHALL be catchable on its own, without catching other workflow failures.

#### Scenario: The work settles before the deadline

- **WHEN** a workflow awaits an activity under a one-minute deadline
- **AND** the activity completes after ten seconds
- **THEN** the await returns the activity result
- **AND** no timeout failure is raised

#### Scenario: The deadline elapses first

- **WHEN** a workflow awaits an activity under a thirty-second deadline
- **AND** the activity has not completed when the deadline elapses
- **THEN** the await raises a timeout failure naming the elapsed deadline
- **AND** the workflow can catch that failure and continue on its compensation path

#### Scenario: An empty answer is not a deadline

- **WHEN** a workflow awaits under a deadline work that legitimately returns `null`
- **AND** that work settles before the deadline
- **THEN** the await returns `null`
- **AND** no timeout failure is raised

### Requirement: A deadline cancels the work it was bounding

When a deadline elapses, the work it was bounding SHALL be cancelled rather than left running. When
the work settles first, the deadline SHALL be cancelled rather than left to fire.

#### Scenario: The bounded activity is cancelled when the deadline elapses

- **WHEN** an activity awaited under a deadline is still pending when the deadline elapses
- **THEN** that activity is cancelled
- **AND** its later completion does not resume the workflow

#### Scenario: The deadline does not fire once the work has settled

- **WHEN** work awaited under a deadline settles before the deadline
- **THEN** the deadline is cancelled
- **AND** the execution is not woken again when the original deadline would have elapsed

### Requirement: A deadline verdict is stable across replay

A deadline verdict SHALL be derived from the recorded history of the execution, never from the
clock of the process performing the replay. Replaying an execution SHALL reach the verdict the
original execution reached.

This SHALL hold when the awaited event arrives after the deadline elapsed: an event recorded after
the deadline SHALL NOT settle a wait that already timed out.

#### Scenario: Replay after a timeout reaches the same verdict

- **WHEN** an execution that timed out on a deadline is replayed
- **THEN** the replay raises the same timeout failure at the same point
- **AND** no new work is scheduled for the bounded call

#### Scenario: An event that arrives after the deadline does not undo the timeout

- **WHEN** a workflow waits for a signal under a deadline
- **AND** the deadline elapses
- **AND** the signal is delivered afterwards
- **THEN** the wait keeps its timeout verdict on every subsequent replay
- **AND** the workflow does not observe two different outcomes for that wait

#### Scenario: A late signal remains available to a later wait

- **WHEN** a signal is delivered after a wait for that signal name has timed out
- **AND** the workflow waits for the same signal name again
- **THEN** that later wait observes the delivered signal

### Requirement: Waiting for a signal under a deadline

A workflow SHALL be able to wait for a signal under a deadline. Without a deadline, the wait SHALL
behave exactly as it does today: it waits indefinitely.

#### Scenario: The signal arrives before the deadline

- **WHEN** a workflow waits for an approval signal under a one-hour deadline
- **AND** the signal is delivered after ten minutes
- **THEN** the wait returns the signal payload

#### Scenario: The deadline elapses before the signal

- **WHEN** a workflow waits for an approval signal under a one-hour deadline
- **AND** no such signal is delivered within the hour
- **THEN** the wait raises a timeout failure
- **AND** the workflow can catch it and take its expiry path

#### Scenario: A wait without a deadline is unchanged

- **WHEN** a workflow waits for a signal without a deadline
- **THEN** the wait does not time out
- **AND** it settles only when the signal is delivered

### Requirement: Backends agree on the verdict

A deadline SHALL be expressed in terms of primitives every backend already supports, and SHALL NOT
require backend-specific handling. The in-memory backend and the Temporal backend SHALL reach the
same verdict for the same execution.

#### Scenario: The same workflow times out identically on both backends

- **WHEN** a workflow that awaits under a deadline is run on the in-memory backend and on the
  Temporal backend, with the awaited work failing to settle in time in both
- **THEN** both executions raise a timeout failure at the same point
- **AND** both histories record the deadline as a timer that fired

