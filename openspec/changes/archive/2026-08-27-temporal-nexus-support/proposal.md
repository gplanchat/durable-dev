## Why

Temporal Nexus lets a workflow call an operation owned by another team or namespace, without
sharing a task queue or a codebase. Today the component has **no Nexus support at all**: a survey
of `src/Durable`, `src/DurableBundle` and `src/Bridge/Temporal` finds zero references outside the
generated protobuf classes.

The generated API is already complete, so nothing has to be regenerated:

- both commands (`COMMAND_TYPE_SCHEDULE_NEXUS_OPERATION`, `COMMAND_TYPE_REQUEST_CANCEL_NEXUS_OPERATION`);
- the nine `EVENT_TYPE_NEXUS_OPERATION_*` history events;
- 59 message classes under `Api/Nexus/V1`, `Api/Command/V1`, `Api/History/V1` and `Api/Failure/V1`;
- the worker RPCs `PollNexusTaskQueue`, `RespondNexusTaskCompleted`, `RespondNexusTaskFailed`;
- the operator RPCs `CreateNexusEndpoint`, `DeleteNexusEndpoint`, and siblings.

Server support was verified against a local Temporal 1.31.2 dev server by creating and deleting a
Nexus endpoint.

This proposal covers the **caller** side only. Serving Nexus operations (the handler side) is a
separate change; see Non-goals in `design.md`.

## What Changes

- A workflow SHALL be able to schedule a Nexus operation on a named endpoint and await its result,
  the same way it awaits an activity.
- A scheduled Nexus operation SHALL survive replay: it is scheduled once, and its outcome is read
  back from history on every subsequent workflow task.
- A workflow SHALL be able to cancel a Nexus operation it is waiting on, and workflow cancellation
  SHALL cancel the pending operation.
- Nexus operation failures SHALL surface to the workflow as typed failures, distinguishing an
  operation failure from a handler error and from a timeout.
- Endpoint, service and operation names, and the operation timeouts, SHALL be value objects
  validated at construction — consistent with `TaskQueue`, `WorkflowNamespace` and `ActivityTimeouts`.
- **BREAKING** none. Every addition is opt-in; existing workflows are unaffected.
- The in-memory backend SHALL reject a Nexus call with an explicit error rather than pretend to
  support it. Nexus is cross-namespace by nature and has no in-process equivalent.

## Capabilities

### New Capabilities

- `nexus-operations`: calling a Temporal Nexus operation from a workflow — scheduling, replay,
  cancellation, failure classification, and the backend support matrix.

### Modified Capabilities

<!-- None: this is the first spec in the repository, and no existing documented requirement changes. -->

## Impact

- **Domain** (`src/Durable`): a new awaitable family and its replay slot, three new value objects,
  two new methods on `WorkflowCommandBufferInterface` and `WorkflowHistorySourceInterface`, one new
  failure exception.
- **Temporal bridge** (`src/Bridge/Temporal`): command construction in `TemporalWorkflowCommandBuffer`,
  history reading in `TemporalExecutionHistory`, and event conversion in `TemporalEventConverter`.
- **In-memory backend**: `EventStoreCommandBuffer` and `EventStoreHistorySource` refuse Nexus
  explicitly.
- **Tests**: unit coverage for the value objects and replay slotting; an integration test against a
  real server, using a Nexus endpoint that targets a task queue served by our own worker.
- **Dependencies**: none. No protobuf regeneration, no new Composer package.
