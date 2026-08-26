## Purpose

Signal and update handlers invoked by the engine: how a handler is declared, in what order handlers
run, how their application interleaves with the conditions that observe them, and what separates an
update from a signal.

## ADDED Requirements

### Requirement: A handler is declared by attribute

A workflow SHALL declare the handler for a signal or an update as an annotated method on its class,
and the engine SHALL wire it.

There SHALL be no second way to declare one. A workflow SHALL NOT be able to register a handler
imperatively on the environment it receives.

#### Scenario: An annotated method handles the signal it names

- **WHEN** a workflow class annotates a method as the handler for a signal name
- **AND** that signal is delivered with a payload
- **THEN** the method is invoked with that payload
- **AND** the state it mutates is visible to the workflow body

#### Scenario: Reaching for imperative registration

- **WHEN** workflow code tries to register a signal or update handler on its environment
- **THEN** the code does not compile

#### Scenario: A message with no declared handler is recorded and ignored

- **WHEN** a signal is delivered for a name the workflow declares no handler for
- **THEN** the delivery is recorded in the journal
- **AND** the execution is not failed by it

### Requirement: Handlers run in journal order, interleaved with condition evaluation

Handlers SHALL be invoked in the order their messages are recorded. A pending condition SHALL be
re-evaluated after each handler returns, before the next message is applied, so that a workflow
resumes on the first message that made its condition hold and not on a later one.

#### Scenario: Two signals are handled in the order recorded

- **WHEN** two signals for the same workflow are recorded in a known order
- **THEN** their handlers are invoked in that order
- **AND** the order is the same on every replay

#### Scenario: The workflow resumes on the message that satisfied it

- **WHEN** a workflow awaits a condition that two successive deliveries would each satisfy
- **THEN** the workflow resumes after the first is applied
- **AND** the second is applied to the state the resumed workflow left behind

### Requirement: The same message name can be delivered repeatedly

A workflow SHALL be able to receive the same signal name any number of times. Each delivery SHALL
reach the handler exactly once, in recorded order, on a first execution and on every replay.

What the workflow keeps of those deliveries SHALL be workflow state, not engine bookkeeping.

#### Scenario: Three deliveries reach the handler three times

- **WHEN** the same signal name is delivered three times with different payloads
- **THEN** the handler is invoked three times, with those payloads in recorded order
- **AND** a replay invokes it three times with the same payloads

#### Scenario: A workflow consumes deliveries at its own pace

- **WHEN** a handler records each delivery in workflow state
- **AND** the workflow body awaits a condition on that state, one delivery at a time
- **THEN** each await returns the next recorded delivery
- **AND** a delivery recorded while no await was pending is still observed by the next one

### Requirement: An update handler answers

An update handler's **return value** SHALL be the response the caller receives. A handler that
raises SHALL make the update fail with that failure, without failing the workflow execution.

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
- **THEN** the replay reproduces the recorded response
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
