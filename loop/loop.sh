#!/usr/bin/env bash
# The heartbeat. One tick = one decision.
#
# Exit codes: 0 quiet/done, 1 verify-failed, 2 model rerouted or refused (do not iterate on the
# output), 3 budget breached, 4 the conductor tried to act (see the permission_denials check).
#
# Deliberately boring: no retries that hide failures, every exit code meaningful, every call
# logged with its real cost.
#
# Differences from the article's script, all forced by the tools actually on this machine:
#   * There is no `llm` CLI here, so every seat is `claude -p --model`. One CLI, no new dependency.
#   * `claude -p` has no --max-tokens flag; the CLI manages the output ceiling itself.
#   * The article estimates dollars from token counts. The CLI reports total_cost_usd directly,
#     so log-cost.sh records the real figure instead of an estimate.
set -euo pipefail
cd "$(dirname "$0")"

MEM=memory
STAMP=$(date +%F)

# Prompts asking for bare output are instructions, not guarantees: both seats intermittently wrap
# their answer in a markdown fence. Unfenced, two things break silently — a fenced "QUIET" never
# equals QUIET, so a quiet repository wakes the expensive seat on every tick, and a fenced work
# order dies in jq halfway through. Strip the fence once, here, rather than trusting the prompt.
unfence() { sed -e '/^[[:space:]]*```/d' ; }

# The three seats. Variables, not constants: a model outage, a price change or a compliance
# ruling is then a config edit rather than an incident (WA007). Defaults are the current
# generation as of 2026-09-06 — the article's Fable 5 is the alternative decision seat, set
# CONDUCTOR_MODEL=claude-fable-5-1 for it.
CONDUCTOR_MODEL="${CONDUCTOR_MODEL:-claude-opus-5}"
WORKER_MODEL="${WORKER_MODEL:-claude-haiku-4-5}"
VERIFIER_MODEL="${VERIFIER_MODEL:-claude-sonnet-5}"

# Opening a real draft PR is the one outward-facing thing a tick does. Off by default so that a
# hand-run tick (CHECK 3) cannot leave junk PRs on the repository; cron turns it on.
LOOP_PUSH="${LOOP_PUSH:-0}"

# ---- 0. budget gate (Layer 6) ------------------------------------------
./scripts/cost-check.sh --budget || exit 3

# ---- 1. triage: cheap model reads the world ----------------------------
CONTEXT=$( { git log --oneline -15;
             gh issue list --limit 10 2>/dev/null || true;
             gh run list --limit 5 2>/dev/null || true; } )
TRIAGE=$(printf '%s' "$CONTEXT" | claude -p "$(cat triage.md)" \
  --model "$WORKER_MODEL" --allowedTools "" --output-format json)
./scripts/log-cost.sh triage "$WORKER_MODEL" "$TRIAGE"
FINDINGS=$(printf '%s' "$TRIAGE" | jq -r '.result' | unfence | sed -e 's/[[:space:]]*$//' -e '/^$/d')
[ "$FINDINGS" = "QUIET" ] && { echo "$STAMP quiet"; exit 0; }

# ---- 2. conductor: the decision seat (read-only, cached prefix first) ---
# Stable prefix (constitution, contract, prompt) leads; volatile findings trail — this ordering
# is the whole of the caching discount, and Layer 6 checks it held.
ORDER=$(claude -p "$(cat ../CLAUDE.md contract.md conductor.md)

## STATE
$(cat $MEM/STATE.md)
## TRUST LEDGER
$(cat $MEM/trust.tsv)
## TODAY'S FINDINGS
$FINDINGS" \
  --model "$CONDUCTOR_MODEL" \
  --allowedTools "Read,Grep,Glob" \
  --output-format json < /dev/null)

# Refusals arrive as HTTP 200 with a normal-looking body. Check before using the result.
STOP=$(printf '%s' "$ORDER" | jq -r '.stop_reason // "end_turn"')
if [ "$STOP" = "refusal" ]; then
  echo "$STAMP ALERT REROUTE: classifier refusal at conductor" >> $MEM/STATE.md
  exit 2
fi

# The read-only wall holds, but it holds *silently*: a denied Write still returns
# stop_reason "end_turn" and a cheerful message about having requested permission. Nothing in the
# happy path would notice. A conductor that reached for a write is either injected or has drifted
# off its seat, so the tick stops and pages instead of acting on that decision.
DENIALS=$(printf '%s' "$ORDER" | jq -r '.permission_denials | length')
if [ "$DENIALS" != "0" ]; then
  echo "$STAMP ALERT conductor attempted $DENIALS tool call(s) outside its seat" >> $MEM/STATE.md
  printf '%s' "$ORDER" | jq -r '.permission_denials[].tool_name' >> $MEM/STATE.md
  exit 4
fi

./scripts/log-cost.sh conductor "$CONDUCTOR_MODEL" "$ORDER"

# An unparseable decision is not something to iterate on — it is a rerouted or malformed seat.
DECISION=$(printf '%s' "$ORDER" | jq -r '.result' | unfence | jq -c '.' 2>/dev/null) || {
  echo "$STAMP ALERT conductor returned no parseable work order" >> $MEM/STATE.md
  printf '%s' "$ORDER" | jq -r '.result' | head -20 >> $MEM/STATE.md
  exit 2
}
ACTION=$(printf '%s' "$DECISION" | jq -r '.action')
SKILL=$(printf '%s' "$DECISION"  | jq -r '.skill')
printf '%s\t%s\t%s\n' "$STAMP" "$SKILL" "$ACTION" >> $MEM/dispatch.tsv

[ "$ACTION" = "stop" ]  && exit 0
[ "$ACTION" = "queue" ] && { echo "queued: $DECISION" >> $MEM/STATE.md; exit 0; }

# What the trust tier gates is the PR, not the attempt. Gating the attempt deadlocks the ledger:
# a skill can only reach `auto` by accumulating runs, and it can only accumulate runs by running.
# So every tier executes, in a throwaway worktree on a throwaway branch, and every outcome is
# recorded — but only a skill at `auto` may open a PR without a human. Below that the branch is
# left sitting for someone to look at, which is exactly what weeks 1 and 2 of the rollout are.
TIER=$(./scripts/trust-log.sh --tier "$SKILL")

# ---- 3. worker: cheap model executes in a worktree ---------------------
REPO_ROOT=$(git rev-parse --show-toplevel)
WT="$REPO_ROOT/.worktrees/loop-$SKILL-$STAMP"
git worktree add "$WT" -b "loop/$SKILL-$STAMP" 2>/dev/null || true
BASE=$(cd "$WT" && git rev-parse HEAD)
(
  cd "$WT"
  printf '%s' "$DECISION" | claude -p "$(cat "$REPO_ROOT/loop/workers/implement.md")" \
    --model "$WORKER_MODEL" \
    --allowedTools "Read,Grep,Glob,Edit,Write,Bash(git *),Bash(composer test*),Bash(vendor/bin/*)" \
    --output-format json > "$REPO_ROOT/loop/$MEM/last-worker.json"
)
./scripts/log-cost.sh worker "$WORKER_MODEL" "$(cat $MEM/last-worker.json)"

# ---- 4. verifier: fresh context judges spec against diff, nothing else --
# Diff against the branch point, not the index. Plain `git diff` shows neither untracked files nor
# anything the worker committed — and the worker prompt mandates TDD, so the new failing test is
# precisely the untracked file that would go missing. The verifier would then be asked to judge
# done_when against a diff with the test cut out of it. Verified: touch a new file and modify a
# tracked one, and `git diff` reports one of the two.
DIFF=$(cd "$WT" && git add -A && git diff "$BASE")

# A no-op must never reach the ledger. An empty diff satisfies the verifier vacuously (nothing in
# it exceeds the spec) and passes the gate (the tree is unchanged), writing an unearned pass into
# the trust ledger. Twenty of those and a skill that has done nothing is promoted to `auto` — the
# ledger that decides autonomy, poisoned by the loop idling. Not a run, so not recorded as one.
if [ -z "$DIFF" ]; then
  echo "$STAMP $SKILL produced no diff — not recorded as a run" >> $MEM/STATE.md
  exit 1
fi
VERDICT_JSON=$(printf 'SPEC:\n%s\n\nDIFF:\n%s' "$DECISION" "$DIFF" \
  | claude -p "$(cat workers/verify.md)" \
    --model "$VERIFIER_MODEL" --allowedTools "" --output-format json)
./scripts/log-cost.sh verifier "$VERIFIER_MODEL" "$VERDICT_JSON"
VERDICT=$(printf '%s' "$VERDICT_JSON" | jq -r '.result')

if printf '%s' "$VERDICT" | grep -q '^PASS'; then
  # ---- 5. the gate votes last ------------------------------------------
  if ( cd "$WT" && ./loop/guardrails/verify.sh ); then
    if [ "$LOOP_PUSH" = "1" ] && [ "$TIER" = "auto" ]; then
      ( cd "$WT" && git push -u origin HEAD && gh pr create --fill --draft )
    else
      echo "$STAMP $SKILL passed the gate; no PR (LOOP_PUSH=$LOOP_PUSH, tier=$TIER). Branch: loop/$SKILL-$STAMP" >> $MEM/STATE.md
    fi
    ./scripts/trust-log.sh "$SKILL" pass
    exit 0
  fi
fi
./scripts/trust-log.sh "$SKILL" fail
echo "$STAMP FAILED: $SKILL — $VERDICT" >> $MEM/STATE.md
exit 1
