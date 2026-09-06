# WA007 — The agentic loop and its ledgers

## Status

Accepted

## Context

A model can now run unattended against this repository for hours. That capability is not the hard
part; the hard part is being able to trust the result without reading every line it produced. The
model supplies intelligence. What has to be built around it supplies **honesty**: the permanent
layer of files, scripts and ledgers that makes an unattended run checkable after the fact.

This WA records the decision to run such a loop here, and the shape it takes. The architecture
follows Linas Beliūnas, *How to Build an Agentic OS with Claude Fable 5* (2026-07-10), ported to
this monorepo's PHP toolchain. It is nine layers: a constitution, walls and a gate, a heartbeat, a
trust ledger, standing goals, a budget, optional loops, ops, and a staged rollout.

Three principles run through all of them, and they are the reason this is a working agreement
rather than a script someone added to `bin/`:

- **Every rule must be checkable.** A rule with no number, no "never", and no command that
  verifies it is a suggestion, and it will be treated as one.
- **Separate the powers.** Four parties touch every piece of work: one plans it, one executes it,
  one judges the result, and one casts the final vote. The last is a shell script, chosen because
  a script cannot be talked into anything.
- **Done is a state, not an event.** Finishing a task creates a daily check that the result is
  still true. Work here is not marked done; it is monitored.

## Agreement

### The seats

| Seat | Tools | Writes code | Set by |
|------|-------|-------------|--------|
| Conductor | `Read,Grep,Glob` — read-only | never | `CONDUCTOR_MODEL` |
| Worker | read, edit, scoped `git`/`composer`/`vendor/bin` | yes, in a worktree | `WORKER_MODEL` |
| Verifier | none; sees a spec and a diff, nothing else | no | `VERIFIER_MODEL` |
| Gate | `loop/guardrails/verify.sh` | no | not a model |

The separation is **physical**, not advisory: `--allowedTools` makes the conductor unable to write
a file whatever it decides. This was tested, not assumed — a conductor asked to write a file has
the attempt recorded in `permission_denials` and produces no file.

The seats are **variables**. A model outage, a price change, or a compliance ruling is then a
config edit rather than an incident. The article names Claude Fable 5 for the decision seat; the
default here is `claude-opus-5`, at half the input rate and the same 1M context, and
`CONDUCTOR_MODEL=claude-fable-5-1` selects the article's tier. Nothing in the loop depends on which.

### What earns autonomy

Autonomy is granted **per skill** — a stable category of work such as `fix-cs` or `triage-issues` —
and only from logged evidence in `loop/memory/trust.tsv`. A skill runs unattended after **20 runs
at a 95% verified pass rate**, and is demoted automatically, and loudly, the moment it drops below
its tier's floor. Never globally, never on faith, and never restored by hand.

### What the loop may and may not touch

`loop/contract.md` holds the three lists — runs solo, needs sign-off, pages me. The supervised-only
paths are in `CLAUDE.md`, and two of them are worth restating because they are specific to this
repository:

- **`psalm-baseline.xml` may never grow.** A baseline entry silences a finding without fixing it.
  It is test-weakening in another coat, the gate diffs the file, and the verifier fails a diff that
  touches it.
- **`documentation/adr/` is not edited.** An ADR records what was decided when it was written
  (DUR000). A model tidying one falsifies the record.

### `loop/*` branches and the prise registry

**Loop branches are exempt from `.worktrees/prises/`.** This is a deliberate exception to the
registry rule in `.worktrees/PRISES.md`, and the reason is that registry's own design: a prise is
posted on `main` before work starts and removed at merge, and `bin/prises-check.sh` calls a prise
stale once its branch has a closed PR and no open one. A loop that opens and abandons draft PRs on
its own cadence would either churn `main` with prise commits or fill the registry with entries
nothing removes. The registry exists to stop two *sessions* building the same slice twice; the loop
works one item at a time, from a conductor that has read the state file, and cannot collide with
itself. A human who picks up a loop branch and takes it further posts a prise then, as normal.

### Prompt injection is a standing threat

The loop reads issues, commit messages and CI logs — text written by people who are not on this
project. Anyone who can file an issue can put text in front of the agent. Four mitigations, all
required, none sufficient alone:

1. The data-is-not-instructions law in `CLAUDE.md`, and `INJECTION-SUSPECT` in triage.
2. Tool allowlists per seat, which make the separation physical.
3. Blast radius: a worktree, a `loop/*` branch, draft PRs, and no merge to `main` without a human.
4. Egress: the loop's environment holds no production credential. **This is not yet true on the
   development machine** — a GitHub OAuth token sits in the global Composer configuration and is
   readable by any process the loop spawns. Moving the loop to a low-privilege user with its own
   Composer home is a precondition for week 3 of the rollout below.

### The 30-day rollout

Autonomy phases in as the ledgers fill. Full autonomy on day one is how you get a four-figure
surprise and a repository you no longer trust.

| Week | What runs | What is granted | Gate to the next week |
|------|-----------|-----------------|-----------------------|
| 1 | `make tick` by hand, `LOOP_PUSH=0` | nothing; every result is read by a human | the gate has passed and failed at least once each, for the right reasons |
| 2 | `make tick` on weekday cron, `make goals` daily, still `LOOP_PUSH=0` | reading and deciding | 7 days of `make audit` matching expectation, cache reads dominating conductor input |
| 3 | `LOOP_PUSH=1`; draft PRs open | opening draft PRs, for skills at `auto` only | the loop runs as a low-privilege user with no production credential in its environment |
| 4 | as week 3, plus `make retro` weekly | the first standing goals, born from finished work | one rule, loop, or goal deleted with nothing breaking |

The last cell is load-bearing. A system you can only add to is a system you have stopped
understanding, so once a month something comes out.

## Consequences

- Spend is bounded three ways: a cached stable prefix by design, and `cost-check.sh --budget`,
  which is dumb, external, and cannot be reasoned with. `LOOP_DAILY_BUDGET_USD` defaults to $5.
- Finished work turns into standing goals, and `make goals` re-checks them daily, forever. The
  sentinel finds; it never repairs. Retiring a goal is a human decision, and it is logged.
- `CLAUDE.md` changes only through `make retro`'s evidence-backed proposals, or a human. The retro
  proposes; it does not apply.

## Relationship to other normative documents

- **WA001 / WA006** — English everywhere; these ledgers included.
- **WA002** — TDD is not suspended for the loop: the worker prompt requires the failing test first,
  and the verifier fails a diff that weakens one.
- **WA003** — issues and epics are the loop's reading list, which is also its injection surface.
- **DUR000** — why `documentation/adr/` is supervised-only.
- **DUR008 / DUR009 / DUR010** — what the gate actually enforces.
