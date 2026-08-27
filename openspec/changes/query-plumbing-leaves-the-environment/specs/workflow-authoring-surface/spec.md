# workflow-authoring-surface Specification

## ADDED Requirements

### Requirement: Query handlers are declared, never registered

A workflow SHALL declare the handler for a query as an annotated method on its class, and the
engine SHALL wire it.

Workflow code SHALL NOT be able to register, probe or invoke a query handler through the
environment it receives. Those verbs belong to the engine, which reads them from outside the
workflow's execution.

#### Scenario: An annotated method answers the query it names

- **WHEN** a workflow class annotates a method as the handler for a query name
- **AND** that query is asked while the execution is still running
- **THEN** the method's return value is what the caller receives

#### Scenario: A query nobody declared

- **WHEN** a query is asked for a name the workflow declares no handler for
- **THEN** the caller is told the query failed
- **AND** the execution continues undisturbed

#### Scenario: A handler that raises

- **WHEN** a declared query handler raises
- **THEN** the caller is told the query failed
- **AND** the execution continues undisturbed

#### Scenario: Reaching for the imperative form

- **WHEN** workflow code tries to register, probe or call a query handler on the environment
- **THEN** the code does not compile

#### Scenario: Answering a query changes no fact

- **WHEN** a query is answered on a running execution
- **THEN** the journal of that execution is what it was before the query was asked
- **AND** a replay of it produces the same result as if no query had been asked

### Requirement: Signal and update registration stays on the surface

A workflow SHALL still be able to register a signal or an update handler imperatively on the
environment it receives.

The distinction with a query is one of place, not of principle: a signal and an update are
dispatched by the environment itself while the workflow waits, whereas a query is read from
outside the execution. What the workflow uses stays; what only the engine uses leaves.

#### Scenario: A workflow expressed as a callable registers a signal handler

- **WHEN** a workflow that is not a class registers a handler for a signal name
- **AND** that signal is delivered
- **THEN** the registered handler is invoked with the payload

#### Scenario: A workflow expressed as a callable cannot answer a query

- **WHEN** a workflow that is not a class is asked a query
- **THEN** the caller is told the query failed
- **AND** answering it requires expressing that workflow as a class
