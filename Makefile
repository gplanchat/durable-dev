# Ops verbs for the agentic loop (documentation/wa/WA007). Everything that touches PHP shells out
# to the composer scripts, so there is one task vocabulary in this repository and not two.
.PHONY: tick queue trust audit goals retro gate clean-worktrees

tick:            ; ./loop/loop.sh
queue:           ; @grep -E "^queued" loop/memory/STATE.md || echo "queue empty"
trust:           ; @./loop/scripts/trust-log.sh --render
audit:           ; @./loop/scripts/cost-check.sh --report
goals:           ; @./loop/verify-goals.sh
retro:           ; @./loop/retro.sh
gate:            ; @./loop/guardrails/verify.sh
clean-worktrees: ; @git worktree list | awk '/loop-/{print $$1}' | xargs -rn1 git worktree remove --force
