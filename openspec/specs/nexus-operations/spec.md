# nexus-operations Specification

## Purpose

Calling a Temporal Nexus operation from a workflow: how it is scheduled, how it survives replay,
how it is cancelled, how its failures are classified, and which backends support it.
## Requirements
### Requirement: Scheduling a Nexus operation

A workflow SHALL be able to schedule an operation on a Nexus endpoint and await its result. The
call SHALL identify an endpoint, a service, an operation, and an input payload, and MAY carry
schedule-to-close, schedule-to-start and start-to-close bounds and Nexus headers.

Headers supplied by the caller SHALL reach the scheduled operation unchanged. Their keys SHALL be
validated where they are written rather than rewritten by the server: the server lowercases them
silently, so two keys differing only by case would collide and one would be lost without a word.

The awaited value SHALL be the operation result decoded from the payload the handler returned.

#### Scenario: A workflow awaits a Nexus operation result

- **WHEN** a workflow schedules an operation on a registered endpoint and awaits it
- **AND** the handler completes it successfully
- **THEN** the awaited call returns the decoded result
- **AND** the workflow history contains `NEXUS_OPERATION_SCHEDULED` followed by
  `NEXUS_OPERATION_COMPLETED` for that operation

#### Scenario: Timeouts are carried to the server

- **WHEN** a workflow schedules an operation with a schedule-to-close bound
- **THEN** the emitted `ScheduleNexusOperation` command carries that bound
- **AND** the bound appears on the `NEXUS_OPERATION_SCHEDULED` history event

### Requirement: Nexus operations survive replay

A Nexus operation SHALL be scheduled exactly once for a given call site, no matter how many
workflow tasks replay the workflow. On replay, its outcome SHALL be read from history rather than
rescheduled, using positional slots as activities, timers and child workflows already do.

#### Scenario: Replay does not reschedule a pending operation

- **WHEN** a workflow that scheduled a Nexus operation is replayed before the operation completes
- **THEN** no new `ScheduleNexusOperation` command is emitted
- **AND** the awaitable remains unsettled

#### Scenario: Replay resolves a completed operation from history

- **WHEN** a workflow that scheduled a Nexus operation is replayed after
  `NEXUS_OPERATION_COMPLETED` is in history
- **THEN** the awaitable is already settled with the recorded result
- **AND** no new command is emitted

#### Scenario: Two operations at two call sites keep their own slots

- **WHEN** a workflow schedules two Nexus operations in sequence and is replayed
- **THEN** each call site resolves to the operation scheduled at its own position

### Requirement: Cancelling a Nexus operation

A workflow SHALL be able to cancel a Nexus operation it scheduled. Cancelling the workflow
execution SHALL cancel any Nexus operation the workflow is waiting on, as it already does for
activities and timers.

#### Scenario: A pending operation is cancelled on request

- **WHEN** a workflow cancels a Nexus operation it is awaiting
- **THEN** a `RequestCancelNexusOperation` command is emitted for that operation
- **AND** the awaitable is rejected once the server records the cancellation

#### Scenario: Workflow cancellation cancels the pending operation

- **WHEN** cancellation is requested on a workflow awaiting a Nexus operation
- **THEN** the operation is cancelled
- **AND** the workflow observes the cancellation at its await point, as with an activity

### Requirement: Nexus failures are classified

A failed Nexus operation SHALL surface to the workflow as a typed failure that distinguishes:

- an **operation failure** — the handler ran and returned a failure;
- a **handler error** — the handler could not run, carrying the server's retry behaviour;
- a **timeout** — a bound elapsed before the operation finished;
- a **cancellation** — the operation was cancelled.

The failure SHALL carry the endpoint, service and operation names so an unhandled one names the
call site.

#### Scenario: An operation failure reaches the workflow typed

- **WHEN** a Nexus handler completes an operation as failed
- **THEN** the awaiting workflow receives a failure identifying the operation
- **AND** the failure is distinguishable from a handler error and from a timeout

#### Scenario: An unhandled Nexus failure fails the workflow with its origin

- **WHEN** a workflow does not handle a failed Nexus operation
- **THEN** the workflow execution fails
- **AND** the recorded failure names the endpoint, service and operation

### Requirement: Nexus identifiers and bounds are validated before dispatch

Endpoint, service and operation names SHALL be validated when constructed, and the operation
bounds SHALL be expressed as durations, consistent with the existing `TaskQueue`,
`WorkflowNamespace`, `ActivityTimeouts` and `Duration` value objects.

Validation SHALL reject what can only be a mistake — blank names, leading or trailing whitespace,
control characters — and SHALL NOT reject what the server accepts.

#### Scenario: A blank endpoint name is refused at construction

- **WHEN** an endpoint name is empty, blank, or has edge whitespace
- **THEN** construction fails with an error naming the offending value
- **AND** no server round-trip is attempted

#### Scenario: A negative bound is refused at construction

- **WHEN** a Nexus operation bound is built from a negative duration
- **THEN** construction fails

### Requirement: Backend support is explicit

The Temporal backend SHALL support Nexus operations. The in-memory backend SHALL refuse them with
an explicit error naming the limitation, rather than silently ignoring the call or hanging.

Nexus is a cross-namespace boundary and has no in-process equivalent; a workflow that calls a Nexus
operation is not testable on the in-memory harness.

#### Scenario: The in-memory backend refuses a Nexus call

- **WHEN** a workflow running on the in-memory backend schedules a Nexus operation
- **THEN** the call fails with an error stating that Nexus requires the Temporal backend
- **AND** the workflow does not hang waiting for an operation that will never be scheduled

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

### Requirement: A caller waits for an operation that answers later

When a handler answers with a token rather than a result, the server records that the operation
started and correlates its eventual outcome onto the calling execution. The caller SHALL treat that
operation as still in flight, and SHALL resolve it from the outcome the server later delivers.

A caller that treats the start as an outcome fails a workflow on an operation that was going to
answer.

#### Scenario: A started operation is still in flight

- **WHEN** the handler answers with a token
- **AND** no outcome has been delivered yet
- **THEN** the calling workflow is still waiting on that operation
- **AND** the operation is not resolved, neither with a result nor with a failure

#### Scenario: The outcome arrives long after the start

- **WHEN** an operation started with a token
- **AND** the server later delivers its completion onto the calling execution
- **THEN** the caller resolves that operation with the delivered result

#### Scenario: An operation that started and then failed

- **WHEN** an operation started with a token
- **AND** the server later delivers a failure
- **THEN** the caller classifies it the way it classifies any other operation failure

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

### Requirement: Two applications, two namespaces, both roles

A Nexus operation SHALL work between two separate applications running as separate processes, in
separate Temporal namespaces, where each application both calls operations served by the other and
serves operations the other calls.

Everything proven so far ran a caller and a handler in one PHP process, with the tasks driven by
hand. That proves the wire; it does not prove the deployment.

#### Scenario: An immediate operation crosses the namespace boundary

- **WHEN** an application in one namespace calls an operation served by an application in another
- **AND** the serving application answers on the task itself
- **THEN** the calling workflow receives the result
- **AND** neither application is registered in the other's namespace

#### Scenario: A deferred operation crosses it too

- **WHEN** the served operation is fulfilled by a workflow in the serving application
- **THEN** the calling application holds nothing open while that workflow runs
- **AND** the caller receives the workflow's result when it completes

#### Scenario: Each application is on both ends

- **WHEN** application A calls an operation served by B
- **AND** B calls a different operation served by A
- **THEN** both directions resolve independently
