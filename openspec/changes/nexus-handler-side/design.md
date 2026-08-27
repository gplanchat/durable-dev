# Design

## What was probed, and what was assumed

**Probed: nothing. Assumed: all of it.** This is the most server-dependent change proposed for this
component so far, and it is entirely about behaviour nobody here has measured:

- `PollNexusTaskQueue` — long-poll semantics, timeout, what an empty response looks like.
- `RespondNexusTaskCompleted` / `RespondNexusTaskFailed` — payload shape, and which failures are
  retryable from the server's point of view.
- How an **asynchronous** operation reports its eventual result: whether the handler responds once
  with an operation token and the server correlates the completion later, or something else.
- Whether cancellation reaches a handler as a request on the task queue, or only as a state the
  handler must poll for.
- What the server does with a handler that never responds.

The caller side got these right by probing, and DUR036 records that the probe corrected the design
more than once — `NexusOperationTimeouts` refuses a combination the server would have clamped
silently, and `executionBoundOr()` was dropped because the probe showed no bound is required. There
is no reason to expect the handler side to be kinder.

**Nothing in this design should be treated as decided until section 1 of `tasks.md` has run.** The
sections below are the questions to take to the server, written as hypotheses so the probe has
something to falsify.

```
temporal server start-dev --namespace durable-test --port 7233
DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

## The worker is new plumbing, not an extension

The workflow task worker polls, replays a fiber, and responds with commands. A Nexus task worker
polls, calls one handler, and responds with a result. They share the poll-and-respond shape and
nothing else: no history, no replay, no determinism, no slots. Reusing the workflow worker would
mean carrying a replay engine through a code path that never replays.

The consequence for the review: this change adds a second worker to the bridge, and the Symfony
bundle grows a second consumer. That is the bulk of the diff and it should be read as such.

## The two completion shapes are not two features

Nexus lets an operation answer immediately or start a workflow and answer when it finishes. The
asynchronous shape is the interesting one: it is how a Nexus operation becomes durable rather than
just remote. It is also where the correlation between the operation and the workflow that fulfils
it lives, which is the thing this design knows least about.

Hypothesis, to be falsified: the handler responds once, naming a workflow, and the server correlates
that workflow's completion to the operation without the handler being involved again. If that is
wrong — if the handler must observe the workflow and report — the change grows a component nobody
has budgeted, and it should be said before the work starts rather than discovered in week two.

## Failures reuse the caller's classification, or the mismatch is a finding

The caller side already classifies Nexus failures. A handler produces failures on the other side of
the same wire, and the default is that the same vocabulary describes both. Where it does not, the
answer is to record which distinction is missing and why — not to grow a parallel hierarchy that
means almost the same thing.

## Refusing to serve, at registration

The caller side refuses a Nexus call at call time on a backend that cannot route, because that is
when the mistake becomes visible. A handler is different: registration happens at startup, and a
handler registered on a backend with no route is not a call that fails, it is a service that
silently never receives anything. So the refusal belongs at **registration**, not at request time —
there is no request to fail.

## Alternatives considered

- **Handler side as a separate package.** It needs the caller's value objects and its failure
  classification; splitting them would duplicate both or invert the dependency.
- **Serving through the existing workflow task worker.** Discussed above: it drags replay through a
  path that has no history.
- **Synchronous completion only, asynchronous later.** Tempting, and it halves the unknowns. It also
  ships the half that a plain HTTP endpoint already does, and defers the half that is the reason to
  use Nexus at all. If the probe shows the asynchronous shape is much larger than expected, this
  becomes the right split — but as a finding, not as an opening move.
