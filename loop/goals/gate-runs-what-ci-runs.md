predicate: for s in cs:check test phpstan psalm; do grep -q "composer --quiet $s" loop/guardrails/verify.sh || exit 1; done
born: 2026-09-06
source: layer 2 of the agentic OS, CHECK 2
status: satisfied
last-pass: 2026-09-06
on-violation: page me. Do not auto-fix. The gate drifting from CI means the loop opens PRs that pass locally and die in CI, which trains everyone to ignore the gate.
retire-when: CI stops using composer scripts. Retirement is a human decision, logged.
