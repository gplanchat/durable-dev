# Design

## What was probed, and what was assumed

**Probed: nothing. Assumed: everything about the server.** No Temporal server was queried for this
proposal, and the house rule says that is exactly when a wrong assumption gets encoded. Two
questions decide the shape of the work and neither can be answered from the protobuf definitions:

1. **Does the server already reject a divergent command sequence?** If it does, this guard is the
   second line of defence and its value is the message rather than the catch. If it does not, it is
   the only one. The design below assumes it does **not** — the resolution happens client-side,
   before any command is emitted, so the server never sees the wrong value; it sees a workflow that
   asked for something plausible.
2. **How does a failed workflow task behave on retry?** The intent is that a divergence fails the
   *task*, not the *run*, so that reverting the deployment lets the run continue. That rests on the
   server retrying the workflow task, which is believed but not measured here.

Task 1 is the probe. Nothing else should be written before it answers.

```
temporal server start-dev --namespace durable-test --port 7233
DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

## The comparison has to rest on what history already holds

The tempting design adds a `slotIdentity` field to every scheduling event and compares that. It is
also the wrong one: it changes the journal format, which means old histories have no such field and
the guard cannot check the very runs it exists to protect — the ones started before the change.

So the comparison uses identity that is **already recorded**:

| Slot kind | Identity in history | Available today |
|---|---|---|
| Activity | activity name on `ActivityScheduled` | to be confirmed in task 1.4 |
| Nexus operation | endpoint, service, operation name | to be confirmed |
| Child workflow | workflow type | to be confirmed |
| Timer | duration, or nothing | likely nothing — see below |
| Side effect | none, by design | not applicable |

Where the identity is absent, the honest outcome is a **stated gap**, not a widened journal. A timer
carries little that distinguishes it from another timer at the same index; if that is what task 1.4
finds, the guard covers four slot kinds and the proposal says so, rather than pretending to five.

## Failing the task, not the run

A divergence is a deployment mistake, and deployment mistakes get reverted. If the guard failed the
**run**, reverting would not help: the run would already be dead. Failing the **workflow task**
leaves the history intact and the run resumable — put the old code back, the next poll replays
cleanly, and nothing was lost but the time between the two deploys.

This is why the guard belongs in `ExecutionContext` at resolution time and not in a validator over
the finished command buffer: at resolution time nothing has been emitted yet.

## The message is the feature

`NonDeterminismException: mismatch at slot 2` costs the reader a bisect. The message should carry
the diff:

```
Replay divergence at activity slot 2 of execution <id>:
  history recorded : "chargeCard"
  code scheduled   : "reserveStock"
This history was written by a different version of ChargeWorkflow.
```

Naming the execution matters: the first thing anyone does with this error is open that run's
history.

## Alternatives considered

- **Comparing the whole command buffer against history after the fiber completes.** Later, cheaper
  to write, and it emits the wrong value into the workflow's own variables before catching it — the
  workflow may have branched on it already. The guard has to bite at resolution.
- **Warning instead of failing.** A warning in a worker log is not read until someone is already
  investigating corrupt data, which is the situation the guard exists to prevent.
- **Hashing the call site (file and line).** Stable against renames, unstable against every
  refactor that moves a line — it would fire on changes that are not divergences at all.
