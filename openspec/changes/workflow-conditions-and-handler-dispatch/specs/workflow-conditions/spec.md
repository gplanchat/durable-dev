## Purpose

Awaiting a condition over workflow state: when the condition is evaluated, what makes its verdict
the same on every replay, and what a workflow observes when a condition can never become true.

## ADDED Requirements

### Requirement: Awaiting a condition

A workflow SHALL be able to await a condition expressed over its own state. The workflow SHALL
resume as soon as the condition holds, and SHALL NOT resume while it does not.

A condition that already holds SHALL NOT suspend the workflow.

#### Scenario: The condition becomes true and the workflow resumes

- **WHEN** a workflow awaits a condition over a counter it keeps
- **AND** an external input raises that counter above the threshold
- **THEN** the workflow resumes at the point it was waiting
- **AND** it observes the state that made the condition true

#### Scenario: A condition that already holds does not suspend

- **WHEN** a workflow awaits a condition that is already true
- **THEN** the workflow continues without suspending
- **AND** nothing is recorded that would wake it later

### Requirement: A condition verdict is reproducible on replay

Condition evaluation SHALL be staged by recorded journal position: journaled inputs SHALL be
applied in the order they were recorded, and pending conditions SHALL be re-evaluated after each
one. A condition SHALL NOT be evaluated against state that no journaled input produced.

Replaying an execution SHALL resume it at the same journal position at which the original
execution resumed.

#### Scenario: Replay resumes at the same point

- **WHEN** an execution that resumed on a condition is replayed
- **THEN** the replay resumes at the same journal position
- **AND** it takes the same path afterwards

#### Scenario: A verdict already reached is not reversed by later state

- **WHEN** a wait bounded by a deadline gives up at a given journal position
- **AND** an input recorded after that position would have made its condition true
- **THEN** the wait keeps the verdict it reached
- **AND** every subsequent replay reaches that same verdict

#### Scenario: A condition reading outside workflow state is reported

- **WHEN** a workflow awaits a condition whose outcome depends on something the journal does not
  record
- **AND** the execution is replayed
- **THEN** the divergence is reported as a workflow failure naming the condition
- **AND** it is not silently resolved to either outcome

### Requirement: A condition that can never hold is reported, not hung

When a condition does not hold and no journaled input is pending that could change it, the
execution SHALL be reported as unable to advance, with the same treatment as a wait for an input
that is never delivered. It SHALL NOT spin, and it SHALL NOT be reported as complete.

#### Scenario: Nothing can make the condition true

- **WHEN** a workflow awaits a condition that does not hold
- **AND** no input is pending that could change the state it reads
- **THEN** the execution is reported as unable to advance, naming the condition
- **AND** no further work is scheduled for it

### Requirement: A condition composes with a deadline

A condition SHALL be awaitable under a deadline, with the same failure and the same guarantees as
any other bounded wait.

#### Scenario: The condition does not hold before its deadline

- **WHEN** a workflow awaits a condition under a deadline
- **AND** the condition does not hold when the deadline elapses
- **THEN** the await raises a timeout failure
- **AND** an input recorded after the deadline does not undo that verdict
