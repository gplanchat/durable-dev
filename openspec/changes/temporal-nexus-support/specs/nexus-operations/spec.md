## Purpose

Calling a Temporal Nexus operation from a workflow: how it is scheduled, how it survives replay,
how it is cancelled, how its failures are classified, and which backends support it.

## ADDED Requirements

### Requirement: Scheduling a Nexus operation

A workflow SHALL be able to schedule an operation on a Nexus endpoint and await its result. The
call SHALL identify an endpoint, a service, an operation, and an input payload, and MAY carry
schedule-to-close, schedule-to-start and start-to-close bounds and Nexus headers.

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
