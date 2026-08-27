# nexus-operations Specification

## ADDED Requirements

### Requirement: Serving a Nexus operation

A component SHALL be able to declare that it serves a named operation of a named service, and an
incoming request for that operation SHALL reach the declared handler with the caller's payload.

A request for an operation nobody declared SHALL be answered as unhandled, and the component SHALL
continue serving the operations it does declare.

#### Scenario: A declared operation receives its caller's payload

- **WHEN** a component declares a handler for a service and operation
- **AND** a caller invokes that operation with a payload
- **THEN** the handler receives that payload

#### Scenario: An operation nobody serves

- **WHEN** a request arrives for an operation this component does not declare
- **THEN** the caller is told the operation is not handled
- **AND** the component keeps serving its other operations

### Requirement: An operation answers now or answers later

A handler SHALL be able to complete an operation immediately with a result, and SHALL be able to
fulfil it with a workflow whose eventual result becomes the operation's result.

In both shapes the caller SHALL receive the same thing: the operation's result, or a failure.

#### Scenario: Answering immediately

- **WHEN** a handler returns a result
- **THEN** the caller's operation completes with that result

#### Scenario: Answering with a workflow

- **WHEN** a handler fulfils the operation with a workflow
- **AND** that workflow later completes with a result
- **THEN** the caller's operation completes with that result

#### Scenario: The fulfilling workflow fails

- **WHEN** a handler fulfils the operation with a workflow
- **AND** that workflow fails
- **THEN** the caller's operation fails
- **AND** the caller can classify the failure the way it classifies any other operation failure

### Requirement: A handler that fails says why

A handler that raises SHALL fail the caller's operation, and the failure SHALL be classifiable by
the caller using the classification that already applies to operations it calls.

#### Scenario: A raising handler

- **WHEN** a handler raises while serving an operation
- **THEN** the caller's operation fails
- **AND** the caller distinguishes it from an operation that was cancelled or timed out

### Requirement: Cancellation reaches the handler

A caller that cancels an operation SHALL cause the handler to learn of the cancellation, and a
handler SHALL be able to observe it while still working rather than only when it responds.

#### Scenario: Cancelling work in progress

- **WHEN** a caller cancels an operation whose handler is still working
- **THEN** the handler observes the cancellation before it produces a result

#### Scenario: Cancelling an operation a workflow is fulfilling

- **WHEN** a caller cancels an operation that a workflow is fulfilling
- **THEN** the outcome for that workflow is stated rather than left to chance

### Requirement: Serving requires a backend that can route

Declaring a handler on a backend that cannot route Nexus SHALL be refused when the handler is
declared, naming the backend and the limitation.

The refusal SHALL NOT wait for a request: no request will arrive, and a service that silently
receives nothing is the failure this rule exists to prevent.

#### Scenario: Declaring a handler on a backend with no route

- **WHEN** a component declares a Nexus handler while configured on a backend that cannot route
- **THEN** the declaration is refused at that moment
- **AND** the refusal names the backend and what to do instead
