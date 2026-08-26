## Purpose

Awaiting a condition over workflow state: how a condition is expressed, when it is evaluated, what
makes its verdict the same on every replay, and what a workflow observes when a condition can never
hold.

## ADDED Requirements

### Requirement: A condition is awaited like anything else

The single wait of the component SHALL accept a condition — a predicate over workflow state —
wherever it accepts an awaitable. The workflow SHALL resume as soon as the condition holds, and
SHALL NOT resume while it does not.

A condition that already holds SHALL NOT suspend the workflow.

#### Scenario: The condition becomes true and the workflow resumes

- **WHEN** a workflow awaits a condition over a counter it keeps
- **AND** a delivered message raises that counter above the threshold
- **THEN** the workflow resumes at the point it was waiting
- **AND** it observes the state that made the condition true

#### Scenario: A condition that already holds does not suspend

- **WHEN** a workflow awaits a condition that is already true
- **THEN** the workflow continues without suspending
- **AND** nothing is recorded that would wake it later

#### Scenario: A condition takes a deadline like any other wait

- **WHEN** a workflow awaits a condition under a deadline
- **AND** the condition does not hold when the deadline elapses
- **THEN** the await raises the same timeout failure a bounded activity would raise
- **AND** the workflow can catch it and take its expiry path

### Requirement: A condition verdict is positional and reproducible

Journaled messages SHALL be applied one at a time, in recorded order, with pending conditions
re-evaluated after each. A condition's verdict SHALL therefore be the journal position at which it
first held, and replaying an execution SHALL resume it at that same position.

A condition SHALL NOT be evaluated against state that no journaled message produced.

#### Scenario: Replay resumes at the same point

- **WHEN** an execution that resumed on a condition is replayed
- **THEN** the replay resumes at the same journal position
- **AND** it takes the same path afterwards

#### Scenario: A verdict already reached is not reversed by later state

- **WHEN** a condition awaited under a deadline gives up at a given journal position
- **AND** a message recorded after that position would have made the condition hold
- **THEN** the wait keeps the verdict it reached
- **AND** every subsequent replay reaches that same verdict

#### Scenario: Two messages are applied one at a time

- **WHEN** two messages that each affect a pending condition are recorded before the workflow is
  replayed
- **THEN** the condition is re-evaluated after the first is applied, before the second
- **AND** the workflow resumes on the first message that made it hold

### Requirement: A condition reads workflow state and nothing else

A condition SHALL be a function of workflow state alone — state the journal produced, and nothing
else. Anything a replay cannot reproduce SHALL be recorded once and read back, as any other
non-reproducible value already is.

The component SHALL NOT promise to detect a condition that breaks this rule. It detects no other
non-determinism, and detecting this one would require recording a verdict per wait — an event this
change exists to avoid.

#### Scenario: A non-reproducible value is recorded before a condition reads it

- **WHEN** a workflow needs a value the journal does not record in order to decide a condition
- **AND** it records that value once before awaiting
- **THEN** the replay reads back the recorded value
- **AND** the condition reaches the same verdict as the original execution

### Requirement: A condition that can never hold is reported, not hung

When a condition does not hold and no journaled message is pending that could change the state it
reads, the execution SHALL be reported as unable to advance, with the same treatment as a wait for
a message that is never delivered. It SHALL NOT spin, and it SHALL NOT be reported as complete.

#### Scenario: Nothing can make the condition true

- **WHEN** a workflow awaits a condition that does not hold
- **AND** no message is pending that could change the state it reads
- **THEN** the execution is reported as unable to advance, naming the condition
- **AND** no further work is scheduled for it
