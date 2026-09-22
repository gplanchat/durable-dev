---
title: An interruptible AI agent
weight: 20
---

# An interruptible AI agent

> [!WARNING]
> **Prototype.** The code described here lives on the `spike/agent-durable-symfony-ai` branch,
> which has not been merged. It runs — see [How to run it](#how-to-run-it) — but this page publishes
> **the pattern**, not a package: the four decisions below apply to any Symfony AI agent, with or
> without the repository's code.

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

## What the demo shows

The scripted model answers to French trigger words — it is a stand-in, not a language model — so
the phrases below are the ones to type. A real provider decides on its own.

- **A guard on every tool call.** Each tool carries an effect: `read`, `write` or `external`. The
  mode — `standard`, `edition`, `auto` — says which effects pass without asking; anything else
  suspends the workflow until you approve or refuse, with a fifteen-minute deadline. « Quelle est
  la météo à Paris ? » is a read and passes; « envoie un mail » is external and waits for you.
- **A question to the human**, as a questionnaire the workflow waits on, the same way it waits for
  an approval: « demande-moi… », or « lance un import » for a question the model asks on its own
  because the request is ambiguous. Add « choix multiple » for the multi-select form.
- **Watch and alert.** « surveille la livraison » puts the agent to sleep on a business fact.
  `php bin/console app:agent:evenement commande.expediee` raises that fact from the CLI, and the
  agent wakes up knowing what it was doing and why.
- **Delegation.** « délègue… » hands a mission to a sub-agent, which inherits the current mode
  and never gets more authority than its parent.
- **Reasoning in the thread.** The model's reasoning crosses the boundary with the answer and
  shows folded under it.
- **A context budget** on the model call. When the conversation outgrows it, the run compacts it
  into a summary and continues; `?contexte=N` on the chat URL shrinks the budget so you can watch
  it happen. After forty turns the run hands over to a fresh one for cost, not for size.
- **Close and resume.** A closed conversation — or one silent for an hour — ends its execution.
  Resuming opens a new execution that starts from a summary of the old one, which is itself a
  journaled model call.
- **A push channel that is optional.** Mercure pushes « this execution moved »; the page also
  polls, so without the hub it still works, only slower.
- Every one of these is visible in Temporal's UI as events of one `Ai_DurableAgent` workflow.

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

## How to run it

Everything lives under `symfony/` on the branch. Prerequisites: PHP 8.2 with `ext-grpc`, Composer,
Docker. No API key: the scripted model is the default.

```bash
git switch spike/agent-durable-symfony-ai
cd symfony
composer install
docker compose up -d --wait   # Postgres, Temporal on 7234, its UI on 8089, Mercure on 33000
```

Then three terminals, from the same directory — the two Temporal workers are the demo, and they
are separate processes because their gRPC long-polls starve any transport sharing their loop:

```bash
php bin/console messenger:consume durable_temporal_journal
php bin/console messenger:consume durable_temporal_activity
php -S localhost:8012 -t public
```

Open <http://localhost:8012/durable/chat>. The samples are at <http://localhost:8012/>, Temporal's UI
at <http://localhost:8089/> — each conversation is one `Ai_DurableAgent` workflow there. Port 8012 is
a suggestion; any free port does. If 7234 or 8089 are taken, set `TEMPORAL_FRONTEND_PORT` and
`TEMPORAL_UI_PORT` before `docker compose up`, and replace 7234 in the `temporal://` DSNs of
`.env.dev` and `config/packages/messenger.yaml`.

Two things you would not guess:

- **A worker that loses Temporal exits.** It does not retry the connection; `docker compose up`
  first, workers second, and a worker that stopped is started again by hand. Without the workers,
  a message is accepted and nothing moves.
- **To talk to Mistral instead of the script**, put `MISTRAL_API_KEY` in `.env.local` and run
  `php bin/console cache:clear`. Nothing else changes.

With the Symfony CLI, `symfony serve --port=8012 -d` replaces the three terminals: it starts the
workers from `.symfony.local.yaml`, and `docker compose up` as one of them — which means the
Docker stack now lives and dies with the server. `symfony server:stop` stops Temporal too, and
the workers with it. It also sets the hosts to `samples.durable.localhost` and
`agent.durable.localhost` only if you ask: `APP_HOST_*` in `.env` are empty by default, which is
what makes `localhost` work everywhere.

`docker compose down` stops the stack. The journal is in its Postgres volume; `down -v` forgets it.

## What is not proven

- **One real provider, exercised little.** The hand-written converter is gone: the branch uses
  `symfony/ai-mistral-platform`'s normalizers and result converter, and only replaces its HTTP
  client. The default run above never calls it, though — the scripted client answers — and nothing
  in the test suite does either. The long conversations, the overflows and the resumes have all been
  run against the scripted client, not against Mistral.
- **No scripted cross-process crash.** The test runs in memory; the runner replays for real there.
  The demo does run across processes — the web server signals, a worker calls the model, another
  runs the workflow — but killing one mid-conversation and watching it resume is something you do
  by hand, not something the suite does.
- **Reasoning blocks travel; the signature does not.** The first draft journaled reasoning as a
  `reasoning_content` field next to the message, and the Mistral bridge refuses that with a 422.
  Reasoning now travels as `thinking` parts inside `content`, which is Mistral's form, and the
  reader accepts both shapes.

  What stays out is the **signature** — the field whose docblock on `Thinking` says it serves "to
  verify thinking blocks when they are replayed on a subsequent turn". No normalizer in
  `ai-platform` writes it: `AssistantMessageNormalizer` never reads `getSignature()`. Signed
  replay therefore assumes a provider bridge replacing that normalizer — plausible, that is what
  `Contract` is for, but not verified here.

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
