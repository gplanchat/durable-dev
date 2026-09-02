---
title: An interruptible AI agent
weight: 20
---

# An interruptible AI agent

> [!WARNING]
> **Prototype.** The code described here lives on a branch that has not been merged, and there is
> no documented way to start it yet. This page publishes **the pattern**, not a package: the four
> decisions below apply to any Symfony AI agent, with or without the repository's code.

## The problem

An agent that calls tools spends minutes, sometimes hours, working. Meanwhile it does things that do
not undo: it sends an email, it charges a payment, it pushes a price to production.

Two needs collide. First: **someone has to be able to say no** before the dangerous call, and that
someone is in a meeting — they will answer in ten minutes, not within an HTTP timeout's thirty
seconds. Second: **the process is going to restart.** A deploy, an OOM kill, a machine rotating out.
If the agent was seven tool calls into nine, you do not want to pay for the seven again.

Symfony AI's `ToolCallRequested::deny()` hook answers the first need as long as nobody restarts: it
is synchronous and in-process. `maxToolCalls` is an in-memory counter. Both vanish with the process.

## What was built

Symfony AI's agent loop, **driven from workflow code**. A conversation is a workflow execution;
every human message is a signal. Between two messages the workflow is not waiting — it is suspended,
consuming nothing.

The agent itself is unmodified. You compose an ordinary `Provider` with two implementations of your
own:

| Seam | What it becomes |
|---|---|
| `ModelClientInterface` | an `await` on an activity — the only HTTP in the whole agent |
| `ToolExecutorInterface` | one `await` per tool call, preceded by the guard |
| `ToolboxInterface` | a plain schema registry; it no longer executes anything |

`Agent::call()` is called as-is from the workflow. It does not know it is replayable.

## The four decisions

This is the reusable part. None of it requires a dependency.

**1. The low seam is `ModelClientInterface`, not `PlatformInterface`.** This is what makes the
exercise short. `Provider::invoke()` turns the conversation into a flat array *before* it reaches
the client, and the raw response is JSON. At that point there is **nothing to translate**: no
`MessageBag`, no `Content`, no `Thinking`, no `Metadata`. Hooking one level higher, at
`PlatformInterface`, forces you to serialize the whole object tree — for the same result.

**2. What protects you is classifying tools, not having modes.** Every tool carries an `effect`:
`read`, `write` or `external`. The current mode only consults that table. Saying "pushing a price is
`external`, not `write`" is the design act; the mode is merely its consequence. The default is
cautious — an unclassified tool counts as `external` — but that is no excuse for not classifying.

**And most tools deserve nothing.** A tool needs execution safety if it answers yes to at least one
of these:

1. does replaying it twice do harm? (charging twice, sending two emails)
2. can it succeed while a later step fails? — then it needs compensation
3. does it last longer than an HTTP request? — minutes, hours, days
4. does someone have to authorize it?

Four noes — and that is the case for `search_product`, `read_stock`, `get_invoice` — and a plain
activity is enough. Wrapping everything manufactures the problem you claim to be solving.

**3. The idempotency key comes from the workflow, not the tool.** It has to be deterministic on
replay, so it is derived from the execution id and the call id. A tool that builds its own key with
`uniqid()` breaks replay on the first restart — and that is the kind of failure you discover in
production.

**4. Approval is a signal, with a human's deadline.** Not a synchronous `deny()`. And the deadline is
that of someone who reads, thinks and switches windows: the prototype is set to fifteen minutes. It
was first set to 120 seconds, and the approval card vanished under the eyes of the person reading it
— the agent answered "denied, no approval" without anyone having denied anything.

## What Durable brings

- **Human approval that survives a restart.** A workflow waiting three days for an approval signal
  is a different class of thing from an in-process hook.
- **Saga compensation on non-idempotent tools.** The agent that sent the email and then crashed
  needs its return leg.
- **Journaled bounds.** An iteration cap and a cost budget held in workflow state survive a crash;
  an in-memory counter does not.

## What it does not bring

- **Not retries.** Those are table stakes, and `symfony/ai-failover-platform` already covers part of
  it. Watch the inverse trap too: Durable retrying an activity that has itself already failed over
  across three providers is 3×N billable calls.
- **Not reliability.** Durable execution makes a wrong agent **reliably wrong**, and makes an
  infinite loop **infinitely durable**. Failure resilience and reliability are two different things;
  the second needs evals, exit guardrails and bounds, none of which is Durable's business.
- **Not streaming.** An activity returns a value once. Journal the assembled result, stream on a
  side channel.

## What replay measured

The unit test runs on the in-memory runner in distributed mode: every `await` suspends the fiber and
**replays the workflow code from the top**. No crash simulation is needed — replay is the normal
regime.

On a scenario with 3 model calls and 2 tool calls: **6 re-executions** of the workflow code, and yet
the model-invocation activity runs **exactly 3 times** and the tool-call activity **exactly twice**.
The journal short-circuits replay; nothing is paid for twice.

And outbound payloads are **byte-identical across two independent executions** — verified by
mutation: a `uniqid()` slipped into the prompt turns the assertion red. That is what makes replay
safe, and it is fragile: the system prompt and the tool list must be **journaled**, not re-read from
configuration on replay. Otherwise adding a tool changes the replayed prompt.

## What is not proven

- **No real provider.** The response converter is hand-written against the "chat completions" shape.
  Plugging in a `symfony/ai-*-platform` would replace it without touching anything else — but that
  has not been done.
- **No cross-process crash.** The test runs in memory. The runner replays for real there, but an
  actual process restart remains to be demonstrated.
- **Reasoning blocks now travel; the signature does not.** The converter first read only `content`
  and `tool_calls`, so a journaled `reasoning_content` was lost — no exception, no trace, just an
  amputated following turn. It now reads the field and returns a `MultiPartResult`;
  `Message::toContent()` unrolls it into a `Thinking`, and `AssistantMessageNormalizer` puts it back
  on the wire on the next turn. Two tests hold it, both verified by mutation.

  What stays out is the **signature** — the field whose docblock on `Thinking` says it serves "to
  verify thinking blocks when they are replayed on a subsequent turn". No normalizer in
  `ai-platform` writes it: `AssistantMessageNormalizer` concatenates the content into
  `reasoning_content` and never reads `getSignature()`. Signed replay therefore assumes a provider
  bridge replacing that normalizer — plausible, that is what `Contract` is for, but unverifiable
  here: no `ai-*-platform` is installed.

  **The lesson outlives the reasoning field.** This was not a bug fix, it was a **change point**. As
  long as the converter dropped the field, it dropped it *the same way on every replay* —
  deterministic, therefore safe. The day it is fixed, the journal still holds the same JSON but the
  converter extracts more from it: the reconstructed message carries one more field, and the payload
  for turn N+1 no longer matches what was originally sent. Every in-flight execution diverges. Here
  that costs nothing — a prototype, nothing in flight. In production this kind of fix gets declared
  and guarded; it does not slip into a patch.

  The upside came from one choice: journaling the **raw** response rather than a converted DTO. The
  reasoning was already in the journal of every past execution, before anything read it. The journal
  carries fields the converter does not know about yet — that is what made the fix free.
- **Failure classification.** A failing tool activity currently kills the agent call. That is a
  default, not a decision.
- **The ground moves.** `symfony/ai` is 0.x, thirteen minor versions so far, with no compatibility
  promise. The four seams used are public interfaces, but nothing guarantees their shape at the next
  minor. That is why this is a pattern and not a package.
