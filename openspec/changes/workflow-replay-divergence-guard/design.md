# Design

## What was probed, and what was measured

**Probed: both questions, against `temporal server start-dev` 1.31.2, namespace `durable-test`.**
The method: one workflow type, two bodies. `v1` schedules `double` at activity slot 0, `v2`
schedules `append` there. A run is started on `v1`, allowed to complete its activity and suspend on
a timer; the `v1` worker is then killed and a `v2` worker takes over the timer's wake-up.

### 1. Does the server reject a divergent command sequence? **No.** (assumption confirmed)

The `v2` code asked for `append('CODE-V2')` — a string. It received `42`, the recorded result of
`double(21)`, and the run **completed successfully**:

```
result: ['variant' => 'v2', 'slot0' => 42]
history: … ACTIVITY_TASK_COMPLETED, …, TIMER_FIRED, WORKFLOW_TASK_COMPLETED,
         WORKFLOW_EXECUTION_COMPLETED
```

No rejection, no task failure, nothing in history marking the divergence. The resolution happens
client-side before any command is emitted, exactly as assumed — so **this guard is the only line of
defence**, not the second.

It is also worse than the proposal claimed. The proposal said wrong data enters the workflow. What
was measured is that the run **terminates successfully** with wrong data, and the journal records
that success. There is no later moment at which anything notices.

### 2. Does a failure leave the run resumable? **No, and not the way this design assumed.** (refuted)

Throwing from workflow code — which is what the guard was going to do — produced:

```
… WORKFLOW_TASK_COMPLETED, WORKFLOW_EXECUTION_FAILED
```

Zero `WORKFLOW_TASK_FAILED` events. The bridge turns a throw into a **FailWorkflowExecution
command**, so the task completes and the *run* dies. Restoring the old code did not bring it back:
the run stays failed.

The cause is in the tree: `RespondWorkflowTaskFailed` exists in `src/Bridge/Temporal/Api` as a
generated gRPC stub and **is never called** anywhere in the bridge. There is no task-failure path
at all today.

**Consequence for this change.** "Fail the task, not the run" — the property the section below
rests on — is not something the guard can obtain by raising. It requires the bridge to gain the
ability to respond `RespondWorkflowTaskFailed` instead of emitting a failure command, and that is a
prerequisite, not a detail. Section 2 of `tasks.md` gains it.

Without it the guard is still worth having, and the trade should be stated rather than assumed: a
guard that raises converts *silent corruption* into a *dead run*. That is an improvement — a dead
run is visible and its history is intact — but it is not the revert-and-resume story, and it must
not be sold as one.

### 3. What identity does each scheduling event already carry?

| Event | Identity recorded | Usable by the guard |
|---|---|---|
| `ActivityScheduled` | `activityName` | **yes** |
| `NexusOperationScheduled` | `endpoint`, `service`, `operation` | **yes**, the full triple |
| `ChildWorkflowScheduled` | `childWorkflowType` | **yes** |
| `TimerScheduled` | `timerId`, `scheduledAt`, `summary` | **no** |

Timers carry nothing comparable, as the design anticipated. `timerId` is generated,
`scheduledAt` is an **absolute due date** — `EventStoreCommandBuffer::startTimer()` stores
`clock() + delay`, so a replay cannot recompute it and the original delay is not recorded anywhere.
`summary` is author-supplied and defaults to `''`: a guard resting on it would cover only labelled
timers and would fire on a relabel, which is not a divergence.

**The gap is stated, not filled.** Four slot kinds are covered; timers are not.

### Reproducing

```
temporal server start-dev --namespace durable-test --port 7233
DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

## The comparison has to rest on what history already holds

The tempting design adds a `slotIdentity` field to every scheduling event and compares that. It is
also the wrong one: it changes the journal format, which means old histories have no such field and
the guard cannot check the very runs it exists to protect — the ones started before the change.

So the comparison uses identity that is **already recorded**:

Section 3 above measured what each event holds: activities, Nexus operations and child workflows
carry usable identity; **timers do not**. The guard therefore covers four slot kinds, and the fifth
is a stated gap rather than a widened journal.

## Failing the task, not the run — which the bridge cannot do yet

A divergence is a deployment mistake, and deployment mistakes get reverted. If the guard failed the
**run**, reverting would not help: the run would already be dead. Failing the **workflow task**
leaves the history intact and the run resumable — put the old code back, the next poll replays
cleanly, and nothing was lost but the time between the two deploys.

**The probe showed the bridge has no way to do this today** (section 2 above). Raising fails the
run. So this section describes the target, and reaching it means teaching the bridge to respond
`RespondWorkflowTaskFailed` — task 2.1.

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
