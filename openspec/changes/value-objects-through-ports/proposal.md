## Why

The scheduling value objects — `ActivityOptions`, `RetryLimit`, `Duration`, `ActivityTimeouts`,
`WorkflowTimeouts`, `TaskQueue`, `CronSchedule`, `SearchAttributes` — stop at the port. The core
flattens them to arrays and floats on the way out, and every driver rebuilds them on the way in:

```php
// ExecutionContext:89 — the core flattens
$metadata = null !== $options ? $options->toMetadata() : [];
$this->commandBuffer->scheduleActivity($activityId, $name, $payload, $metadata);

// TemporalWorkflowCommandBuffer:61 — the driver rehydrates, first line
$options = ActivityOptions::fromMetadata($metadata);
```

A round trip through primitives in the middle of the domain. The same happens for child workflows
(`WorkflowTimeouts::fromMetadata`, `SearchAttributes::fromMetadata`,
`TemporalPolicyMapper::parentClosePolicy($schedulingMetadata['parentClosePolicy'])`) and in
`ActivityMessageProcessor:55`.

Three costs, each already paid at least once in this codebase:

- **Invariants do not travel.** `ActivityTimeouts` refuses a heartbeat longer than the attempt.
  Flattened to an array, that guarantee is gone until something rebuilds the object — or never.
- **Types disappear.** `TemporalPolicyMapper::parentClosePolicy()` accepts
  `ParentClosePolicy|string|null` **only** because the value crossed an array.
- **The port misstates its contract.** `startTimer(string $timerId, float $scheduledAt, string
  $summary)` hands the driver an absolute instant. That is where the missing
  `start_to_fire_timeout` bug came from: Temporal wants a duration, and the driver had to infer it.

## What Changes

- The command port SHALL accept scheduling value objects rather than arrays and floats. Each
  driver SHALL translate them into its own primitives.
- `startTimer` SHALL take a **duration**, not an absolute deadline. Turning a delay into an instant
  is the in-memory driver's business; the Temporal driver needs the delay itself.
- The history port SHALL express recorded timings as `Duration` rather than bare floats.
- `ActivityMessage` SHALL carry typed options rather than an untyped metadata array, keeping the
  attempt counter it already exposes.
- Serialisation SHALL live in the adapters. `toMetadata()` / `fromMetadata()` stay on the value
  objects — they are how the **wire** is written — but the core SHALL NOT call them to talk to a
  port.
- **BREAKING** for anyone implementing `WorkflowCommandBufferInterface` or
  `WorkflowHistorySourceInterface` outside this repository. No behaviour changes for workflow
  authors.
- **The wire format does not change.** Same keys, same bytes in the journal and in Temporal
  history. This is an internal refactor, not a data migration.

## Capabilities

### New Capabilities

- `scheduling-ports`: what the core hands to a driver when it schedules work, and who owns the
  translation to a backend's primitives.

### Modified Capabilities

<!-- None: no documented requirement changes; workflow-facing behaviour is untouched. -->

## Impact

- **Ports** (`src/Durable/Port`): `WorkflowCommandBufferInterface`, `WorkflowHistorySourceInterface`.
- **Core** (`src/Durable`): `ExecutionContext` stops calling `toMetadata()`; `ActivityMessage`
  carries typed options; `ActivityMessageProcessor` and `ExecutionRuntime` stop rebuilding options
  from arrays.
- **In-memory driver**: `EventStoreCommandBuffer` and `EventStoreHistorySource` gain the
  serialisation the core loses, and `EventStoreCommandBuffer` computes the deadline from the delay.
- **Temporal driver**: `TemporalWorkflowCommandBuffer` drops its `fromMetadata()` calls;
  `TemporalPolicyMapper` narrows its signatures now that values arrive typed.
- **Tests**: unit coverage moves with the boundary; the integration suite is the guard that replay
  of in-flight executions is unaffected.
- **Dependencies**: none.
