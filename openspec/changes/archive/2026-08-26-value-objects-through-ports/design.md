## Context

Two drivers sit behind one core: an in-memory backend that journals domain events, and a Temporal
backend that emits protobuf commands. They meet the core at two ports —
`WorkflowCommandBufferInterface` (write) and `WorkflowHistorySourceInterface` (read).

Both ports currently speak primitives. The evidence, read from the code rather than assumed:

- `WorkflowCommandBufferInterface::scheduleActivity(string, string, array $payload, array $metadata)`
- `WorkflowCommandBufferInterface::startTimer(string $timerId, float $scheduledAt, string $summary)`
- `WorkflowCommandBufferInterface::scheduleChildWorkflow(string, string, array, array $schedulingMetadata)`
- `WorkflowHistorySourceInterface::findTimerSlotResult(): array{id, scheduledAt: float, failed}`

And the round trip, in three places:

- `ExecutionContext:89` calls `$options->toMetadata()` before `scheduleActivity()`
- `ExecutionContext:183` calls `$options->toSchedulingMetadata()` before `scheduleChildWorkflow()`
- `TemporalWorkflowCommandBuffer:61` calls `ActivityOptions::fromMetadata($metadata)` first thing,
  and lines 179–181 rebuild `WorkflowTimeouts` and `SearchAttributes` the same way
- `ActivityMessageProcessor:55` rebuilds `ActivityOptions` from the message metadata

## Goals / Non-Goals

**Goals:**

- Options reach a driver as the objects the caller built.
- `startTimer` takes a delay; each driver derives what it needs.
- Serialisation happens in adapters, at the wire, not in the core.
- The wire format is untouched, so in-flight executions keep replaying.

**Non-Goals:**

- Changing anything a workflow author writes. `ActivityOptions`, `Duration` and the rest keep their
  current public shape.
- Changing the journal or Temporal history bytes.
- Typing the activity **payload**. It is caller data, genuinely `array` — this change is about
  scheduling options, not business input.
- Introducing an intermediate "command" DTO layer between core and port. The value objects are the
  contract; wrapping them again would trade one indirection for another.

## Decisions

**`startTimer` takes a `Duration`, not a deadline.**
This is the one change that fixes a real defect rather than a smell. The Temporal server wants
`start_to_fire_timeout`, a duration; the in-memory driver wants an instant to compare against its
clock. Handing both an absolute instant made the Temporal side reconstruct a duration by
subtracting its own `microtime()`, which is why the command was long emitted without any timeout at
all. Passing the delay lets each side do its own arithmetic once.

**The history port returns `Duration`, not floats.**
Otherwise the boundary is typed in one direction only, and the asymmetry invites exactly the unit
confusion the value objects were introduced to remove.

**`toMetadata()` / `fromMetadata()` stay on the value objects.**
They are how the wire is written, and the wire is not going away. What changes is who calls them:
adapters, not the core. Moving them out into separate serialiser classes would scatter the wire
contract away from the type that defines it.

**`ActivityMessage` carries typed options.**
It crosses a transport, so it is serialised anyway — but by the transport, which is an adapter.
`attempt()` stays as it is: it is transport bookkeeping, not a scheduling option.

**No new DTO layer.**
An alternative was a `ScheduleActivityCommand` object per port method. Rejected: the value objects
already carry the meaning, and a wrapper would add a translation step on both sides of a boundary
whose whole problem is that it already has one too many.

## Risks / Trade-offs

- **Replay of in-flight executions is what can break silently.** The wire format is unchanged by
  design, but nothing enforces that except tests. The integration suite against a real server is
  the guard, and it must run before and after.
- **The port is public API for anyone implementing a third driver.** This is a breaking change for
  them, with no deprecation window; the changed signatures are the migration guide.
- **The change touches the two drivers at once.** It cannot be landed one driver at a time — the
  port signature is shared. Landing it means both adapters move in the same commit, which is a
  larger diff than this codebase usually takes in one step.
- **A partial migration is worse than none.** Leaving `scheduleActivity` typed while
  `scheduleChildWorkflow` still takes an array would give the boundary two conventions and make the
  next reader guess which applies.
