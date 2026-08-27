# workflow-replay-integrity Specification

## ADDED Requirements

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
