# DUR031 — Value objects across the ports, and who owns the wire

## Status

Accepted

## Context

Scheduling options are value objects: `ActivityOptions`, `RetryLimit`, `Duration`,
`ActivityTimeouts`, `WorkflowTimeouts`, `TaskQueue`, `WorkflowNamespace`, `CronSchedule`,
`SearchAttributes`. They validate what they can at construction, so a mistake surfaces where it is
written rather than as a server rejection or a silently rewritten value.

They stopped at the port. The core flattened them on the way out and every driver rebuilt them on
the way in:

```php
// ExecutionContext — the core flattened
$metadata = null !== $options ? $options->toMetadata() : [];
$this->commandBuffer->scheduleActivity($activityId, $name, $payload, $metadata);

// TemporalWorkflowCommandBuffer — the driver rehydrated, first line
$options = ActivityOptions::fromMetadata($metadata);
```

A round trip through primitives in the middle of the domain. The Temporal command buffer performed
four such rebuilds; `ActivityMessageProcessor` performed a fifth.

Three costs, each already paid in this codebase:

- **Invariants did not travel.** `ActivityTimeouts` refuses a heartbeat longer than the attempt.
  Flattened to an array, the guarantee was gone until something rebuilt the object.
- **Types disappeared.** `TemporalPolicyMapper::parentClosePolicy()` accepted
  `ParentClosePolicy|string|null` **only** because the value had crossed an array, which forced a
  `default` branch where an unknown value could hide.
- **The port misstated its contract.** `startTimer(string $timerId, float $scheduledAt, string
  $summary)` handed the driver an absolute instant. Temporal wants a duration, so the driver
  subtracted its own `microtime()` and clamped the result — which is why
  `start_to_fire_timeout` went unset for a long time and no timer ever fired.

A fourth problem surfaced while writing the guard for this change: the core stamped the queueing
time with a hard-coded `microtime()`, ignoring the clock injected into the backend. A replay engine
reading the wall clock is a defect independent of typing.

## Decision

### Value objects cross the ports

`WorkflowCommandBufferInterface` and `WorkflowHistorySourceInterface` accept and return the value
objects the caller constructed. Drivers translate them into their own primitives.

```php
scheduleActivity(string $activityId, string $activityName, array $payload, ?ActivityOptions $options): void
scheduleChildWorkflow(string $childExecutionId, string $childWorkflowType, array $input, ChildWorkflowOptions $options): void
startTimer(string $timerId, Duration $delay, string $summary): void
```

The activity **payload** stays `array`. It is caller data, genuinely untyped; this decision is
about scheduling options.

### A timer is a delay, not a deadline

`startTimer` receives the duration to wait. `EventStoreCommandBuffer` turns it into a deadline with
its own clock, because that backend compares deadlines; `TemporalWorkflowCommandBuffer` maps it
straight to `start_to_fire_timeout`, because the server wants a duration. Neither performs
arithmetic on behalf of the other, and the core reads no clock.

### Serialisation belongs to the adapters

`toMetadata()` / `fromMetadata()` stay on the value objects — that is how the wire is written, and
the wire is not going away. What changed is **who calls them**: adapters, never the core.

- `EventStoreCommandBuffer` serialises when it records a journal event and stamps the queueing time
  with its clock.
- `TemporalWorkflowCommandBuffer` serialises only for the activity schedule input, which is the
  wire the activity worker will read back.
- `ActivityMessage` carries typed fields — options, attempt, first queued at, retry delay — and
  offers `toWireMetadata()` / `fromWireMetadata()` for the transports that must serialise it.

### The wire format is frozen

Journals and Temporal histories written before this change remain readable, and executions in
flight keep replaying. `tests/unit/Durable/Wire/WireFormatPinTest` pins the exact shape and exists
to **forbid a change**, not to describe a desirable behaviour. Modifying it means a data migration,
not a refactor.

## Consequences

- The boundary states what it carries. A driver receives guarantees rather than a bag of keys.
- `TemporalPolicyMapper` takes enums, so its `match` expressions are exhaustive and no unknown
  value can fall through a `default`.
- The core no longer reads the wall clock. Timer deadlines and queueing timestamps come from the
  backend's clock, which is what lets the test harness advance a virtual clock end to end.
- **Breaking for third-party driver implementers.** Anyone implementing
  `WorkflowCommandBufferInterface` or `WorkflowHistorySourceInterface` outside this repository must
  update three signatures. There is no deprecation window; the changed signatures are the migration
  guide, and a stale implementation fails to load rather than misbehaving at runtime.
- **Not breaking for workflow authors.** No public workflow-facing API changed.
- Both drivers had to move in the same commit: the port signature is shared, and a partial
  migration would have left the boundary with two conventions.

## Alternatives considered

**A command DTO per port method** (`ScheduleActivityCommand` and siblings). Rejected: the value
objects already carry the meaning, and a wrapper would add a translation step on both sides of a
boundary whose whole problem was having one too many.

**Moving serialisation into separate serialiser classes.** Rejected: it would scatter the wire
contract away from the type that defines it, and the wire shape is part of what a value object
means here.

**Changing the wire format at the same time.** Rejected outright. Replay of in-flight executions is
what this change could break silently; freezing the wire is what made it reviewable at all.

## Related decisions

- **DUR002** — the CQRS ports this decision retypes.
- **DUR003** — the fiber and replay model that makes wire stability load-bearing.
- **DUR005** — the two backends that now share a typed boundary.
- **DUR011** — errors, retries and classification, whose options travel across this boundary.
