# workflow-replay-integrity Specification

## ADDED Requirements

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
