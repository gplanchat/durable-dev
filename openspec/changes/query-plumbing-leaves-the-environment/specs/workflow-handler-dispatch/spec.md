# workflow-handler-dispatch Specification

## MODIFIED Requirements

### Requirement: A handler is declared by attribute or registered on the environment

A workflow SHALL be able to declare the handler for a signal or an update as an annotated method on
its class. A workflow SHALL also be able to register a handler for a signal or an update
imperatively, so that a workflow expressed as a callable can declare one.

Both forms SHALL produce the same dispatch.

This SHALL NOT extend to queries. A query handler is declared by attribute only: it is read by the
worker, outside the workflow's execution, whereas a signal and an update are dispatched by the
environment itself while the workflow waits.

#### Scenario: An annotated method handles the signal it names

- **WHEN** a workflow class annotates a method as the handler for a signal name
- **AND** that signal is delivered with a payload
- **THEN** the method is invoked with that payload
- **AND** the state it mutates is visible to the workflow body

#### Scenario: A workflow expressed as a callable registers a handler

- **WHEN** a workflow that is not a class registers a handler for a signal name
- **AND** that signal is delivered
- **THEN** the registered handler is invoked with the payload
- **AND** the workflow behaves exactly as the annotated form would

#### Scenario: A message with no declared handler is recorded and ignored

- **WHEN** a signal is delivered for a name the workflow declares no handler for
- **THEN** the delivery is recorded in the journal
- **AND** the execution is not failed by it

#### Scenario: A query cannot be registered imperatively

- **WHEN** workflow code tries to register a query handler on the environment it receives
- **THEN** the code does not compile
