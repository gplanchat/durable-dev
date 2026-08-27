# workflow-replay-integrity Specification

## Purpose
TBD - created by archiving change workflow-replay-divergence-guard. Update Purpose after archive.
## Requirements
### Requirement: Replay refuses a record that belongs to another call

When an execution is replayed, the engine SHALL resolve a scheduled step from the journal only if
the step the code is asking for is the step the journal recorded at that position.

When they differ, the engine SHALL NOT resolve the step, SHALL NOT let the execution proceed on the
recorded value, and SHALL report the divergence with enough detail to identify it: which execution,
which position, what the journal holds, what the code asked for.

An execution whose code has not changed SHALL be unaffected.

#### Scenario: An activity was inserted ahead of another

- **WHEN** an execution has recorded the result of the activity at a given position
- **AND** the workflow is replayed from code that now schedules a different activity at that
  position
- **THEN** the execution does not receive the recorded result
- **AND** the divergence is reported, naming both the recorded activity and the requested one

#### Scenario: A Nexus operation was retargeted

- **WHEN** an execution has recorded a Nexus operation at a given position
- **AND** the workflow is replayed from code that now targets a different endpoint, service or
  operation at that position
- **THEN** the execution does not receive the recorded result
- **AND** the divergence is reported

#### Scenario: A child workflow changed type

- **WHEN** an execution has recorded a child workflow at a given position
- **AND** the workflow is replayed from code that now starts a child of a different type at that
  position
- **THEN** the execution does not receive the recorded result
- **AND** the divergence is reported

#### Scenario: The code is put back

- **WHEN** an execution has been stopped by a reported divergence
- **AND** the workflow code that wrote the journal is restored
- **THEN** the execution resumes from where it was
- **AND** it completes as if the divergence had never been reported

#### Scenario: An unchanged workflow replays untouched

- **WHEN** an execution is replayed from the code that wrote its journal
- **THEN** every recorded step resolves
- **AND** no divergence is reported

#### Scenario: The same guard on a backend without a cluster

- **WHEN** the divergence above happens on the backend that journals to a single database
- **THEN** it is reported the same way, with the same detail

### Requirement: A declared change point is a sanctioned divergence

A workflow SHALL be able to declare that its behaviour changed at a given point, and SHALL receive
back which behaviour applies to the execution being run.

The engine SHALL fix that answer the first time an execution reaches the point, SHALL record it in
that execution's journal, and SHALL return the recorded answer on every later replay of that
execution — regardless of what the deployed code would otherwise choose.

The engine SHALL NOT report a divergence for steps whose identity differs because of a declared
change point. It SHALL still report one for steps that differ for any other reason.

#### Scenario: A run that started before the change keeps the old behaviour

- **WHEN** an execution reached a declared change point while the old behaviour was deployed
- **AND** the new behaviour is deployed while that execution is still running
- **THEN** the execution continues on the old behaviour
- **AND** no divergence is reported for the steps that differ between the two

#### Scenario: A run that arrives after the change takes the new behaviour

- **WHEN** an execution reaches a declared change point for the first time after the new behaviour
  is deployed
- **THEN** it takes the new behaviour
- **AND** that fact is recorded in its journal

#### Scenario: The answer does not move

- **WHEN** an execution that has passed a declared change point is replayed any number of times
- **THEN** it receives the same answer every time

#### Scenario: Versioning one change point does not disarm the others

- **WHEN** a workflow declares a change point
- **AND** a different step of the same workflow is changed without declaring one
- **THEN** the undeclared change is still reported as a divergence

#### Scenario: Knowing when an old behaviour can be deleted

- **WHEN** an operator asks which live executions are still bound to a given behaviour of a declared
  change point
- **THEN** the answer is observable from the journal
- **AND** an answer of none means the behaviour can be removed without affecting any live execution

#### Scenario: The same primitive without a cluster

- **WHEN** the behaviours above are exercised on the backend that journals to a single database
- **THEN** they hold identically

