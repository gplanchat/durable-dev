## Purpose

Signal and update handlers invoked by the engine: which method a delivered message reaches, in
what order handlers run, how a handler and an explicit wait share the same message, what lets the
same signal be delivered repeatedly, and what separates an update from a signal.

## ADDED Requirements

### Requirement: A signal handler is invoked by the engine

A workflow method marked as the handler for a signal SHALL be invoked when that signal is
delivered, without the workflow body asking for it. The signal payload SHALL be passed to the
handler.

A workflow that declares no handler SHALL behave exactly as it does today.

#### Scenario: The declared handler receives the signal

- **WHEN** a workflow declares a handler for a signal name
- **AND** that signal is delivered with a payload
- **THEN** the handler is invoked with that payload
- **AND** the state it mutates is visible to the workflow body

#### Scenario: A signal with no declared handler is unchanged

- **WHEN** a workflow declares no handler for a signal name
- **AND** that signal is delivered
- **THEN** an explicit wait for that name observes it exactly as before

### Requirement: Handlers run in journal order, before the waits they feed

Handlers SHALL be invoked in the order their messages are recorded in the journal. A handler
SHALL run **before** any wait that its effect resolves, so that a wait never observes a message
its handler has not yet processed.

#### Scenario: Two signals are handled in the order recorded

- **WHEN** two signals for the same workflow are recorded in a known order
- **THEN** their handlers are invoked in that order
- **AND** the order is the same on every replay

#### Scenario: The handler runs before the wait resolves

- **WHEN** a workflow declares a handler for a signal and also waits for it
- **AND** that signal is delivered
- **THEN** the handler is invoked first
- **AND** only then does the wait resolve, with the same payload

### Requirement: The same signal can be delivered and consumed repeatedly

A workflow SHALL be able to receive the same signal name any number of times. Each delivery SHALL
reach the handler once, and each wait for that name SHALL consume one delivery, in recorded order.
A wait that gives up SHALL consume none.

#### Scenario: Three deliveries feed three waits

- **WHEN** the same signal name is delivered three times with different payloads
- **AND** the workflow waits for that name three times
- **THEN** each wait returns the payloads in the order they were recorded
- **AND** the handler was invoked three times

#### Scenario: An abandoned wait consumes nothing

- **WHEN** a wait for a signal name gives up on its deadline
- **AND** that signal is delivered afterwards
- **THEN** the handler is invoked for it
- **AND** a later wait for the same name observes that delivery

### Requirement: An update handler answers

A workflow method marked as the handler for an update SHALL be invoked when that update is
delivered, and its **return value** SHALL be the response the caller receives. A handler that
raises SHALL make the update fail with that failure, without failing the workflow.

An update SHALL be distinguishable from a signal by that response: a signal has none.

#### Scenario: The caller receives the handler's return value

- **WHEN** a caller sends an update to a running workflow
- **AND** the workflow declares a handler for it
- **THEN** the handler is invoked with the update arguments
- **AND** the caller receives the handler's return value as the update response

#### Scenario: A failing update handler does not fail the workflow

- **WHEN** an update handler raises
- **THEN** the caller receives that failure as the update outcome
- **AND** the workflow execution continues

#### Scenario: The response survives replay

- **WHEN** an execution that answered an update is replayed
- **THEN** the replay reproduces the same response
- **AND** the handler is not asked to compute a different one

### Requirement: Both backends dispatch identically

Handler dispatch SHALL be expressed in terms of the journal both backends already record, and the
in-memory backend and the Temporal backend SHALL invoke the same handlers, in the same order, for
the same recorded messages.

#### Scenario: The same workflow handles the same messages on both backends

- **WHEN** a workflow declaring signal and update handlers receives the same messages on the
  in-memory backend and on the Temporal backend
- **THEN** both executions invoke the same handlers in the same order
- **AND** both produce the same update responses
