# Spike: Restate as a third Durable backend

Issue #464, PR #466. Throwaway code: nothing here is imported by `src/`, and no QA tool reads
this directory. This file keeps what the spike learned and what was decided, so that the bridge,
if it is ever built, starts from here and not from memory.

## Run it

```
spike/restate/run.sh
```

It needs Docker and ports 8080, 9070 and 9080 free. It starts `restate-server` 1.7.12, registers
`endpoint.php` under `php -S`, runs one workflow and exits 0 only if:
- the output is `{"activity":"charged","approval":"yes"}`;
- the activity ran once;
- the workflow took three requests.

## What the protocol needs, and its traps

- The public spec (`restatedev/service-protocol`) stops at protocol V3. Server 1.7.12 accepts
  discovery for **V5 to V7** only: the "immutable journal" of commands and notifications. The
  only description is in the server tree: `service-protocol/dev/restate/service/protocol.proto`
  at the release tag. The V6 `SuspensionMessage` is in `legacy.proto` next to it.
- Discovery is `GET /discover`, not the `/discovery` the spec names.
- The spike registers with `use_http_11: true`, because `php -S` speaks HTTP/1.1 only.
- `php -S` workers outlive their parent: `run.sh` kills them by pattern.
- A `run` does not have to suspend. The SDK can send `ProposeRunCompletion` and keep going in the
  same response. The server stores the proposal and replays it as a `RunCompletionNotification`.
  Only real waits (timers, promises, calls) cost a round trip. A response lost after the effect
  ran re-runs it on retry: at-least-once, as on Temporal.
- Every request carries, and replays, the whole journal.
- There are no custom journal entries in V5+. A `$env->version()` marker has to ride on an
  existing command, such as a named `run`.
- Licence: the server is BSL 1.1, with an Additional Use Grant covering production use for your
  own services. The protocol spec is MIT.

## Where a bridge plugs in

- Not behind `EventStoreInterface`: Restate owns the journal, like Temporal.
- The seam is the three ports `Bridge/Temporal/Worker/WorkflowTaskRunner` drives without Messenger,
  all run by `WorkflowFiberDriver`:
  - `WorkflowHistorySourceInterface`: built from the request body;
  - `WorkflowCommandBufferInterface`: written back as frames;
  - `WorkflowLifecycleInterface`: the outcome.
- An HTTP controller replaces the poller.
- Size, by the Temporal measure: about 1,500 lines (`TemporalExecutionHistory` 863,
  `TemporalWorkflowCommandBuffer` 553, `TemporalWorkflowLifecycle` 88).

| Durable | Restate V5+ |
|---|---|
| side effect | `run` |
| activity | `CallCommand` to an activity handler |
| timer | `SleepCommand` |
| signal | workflow promise, or a named signal |
| update | shared handler completing a promise; no accept/validate phase |
| query | none: no read-only replay found; needs a separate invocation or a journal projection |
| `version()` | a named `run` |
| child workflow | `CallCommand` to another workflow key |
| cancellation | built-in `CANCEL` signal |
| Nexus | `CallCommand` within one cluster; no cross-cluster endpoint registry |

## Decided (maintainer, 2026-09-24)

- `recordSideEffect` → a `run` in the workflow's own invocation: a short, local effect whose
  result is journaled.
- `scheduleActivity` → a `CallCommand` to an activity handler. It is the only mapping that keeps
  `ActivityOptions` meaningful, and the same line the Temporal bridge draws.

## Open, deliberately left for later: who retries an activity

Restate declares retry policy and timeouts per handler, in the manifest, not per call. Per-call
options (`#[Activities(..., attempts: 3)]`, #462, or `$env->activityStub($contract, $options)`)
need a translation. Two candidates, not decided:

**1. One handler per option set** (such as `Payment/charge~a3f9`), with Restate retrying.

For:
- a retry does not wake the workflow;
- the retries show in Restate's UI as one invocation;
- `retryPolicyOnMaxAttempts: PAUSE` hands the last failure to an operator.

Against:
- options computed at run time cannot have a handler declared ahead of time, so option 2 is
  needed anyway as a fallback;
- changing an option renames the handler: re-registration, and a replay divergence on the
  `CallCommand` unless the suffix is left out of the comparison;
- old deployments must stay registered while calls target them;
- partial coverage: `attempts` and the backoff map directly, `startToClose` only roughly
  (`inactivityTimeout`/`abortTimeout`), and `scheduleToStart`, `scheduleToClose` and `heartbeat`
  not at all.

**2. Retries kept in Durable.** The handler gets one attempt and the failure reaches the caller.
The execution context applies `ActivityOptions`: a backoff timer, then a new `CallCommand`.

For:
- the same semantics on every backend, which is the RFC's portability promise;
- every option covered except `heartbeat`, dynamic ones included;
- a stable manifest;
- the cost falls on failures only.

Against:
- each retry wakes the workflow twice, each wake replaying a journal that grows per attempt;
- N failed invocations in the UI instead of one retried;
- no Restate-managed pause.

**To verify before choosing option 2:**
- that Restate's default retry policy can really be switched off on those handlers, so the two
  retry layers do not stack;
- that a PHP process crashing mid-activity reaches the caller as a failure.

**Leaning** (not a decision): option 2 by default, and option 1 later as an opt-in optimisation
for attribute-declared options, if retry cost shows in production.
