# Runbook

Every alarm this system can raise, and the exact response. The alarm rows are derived from the
"pages me" list in `loop/contract.md` and from `loop.sh`'s exit codes — the article's own runbook
table is a published image, so this is built from the parts of the source that are text.

Exit codes from `make tick`:

| Exit | Meaning | Response |
|------|---------|----------|
| 0 | quiet, or work done | Nothing. Read `make queue` in the morning. |
| 1 | verifier or gate rejected the work | Read `memory/STATE.md` for the verdict. **Once** is normal — that is the gate working. **Twice on the same item** is a page: the spec is wrong, not the worker. Queue it for a human and stop re-running. |
| 2 | classifier refused, or the model was swapped mid-run | Never build on output from a model you did not choose. Read the `REROUTE` line, re-run once; if it repeats, the prompt is tripping a classifier — check for anything asking the model to explain its reasoning. |
| 3 | daily budget breached | `make audit`. If cache reads are not most of conductor input, the stable-prefix ordering in `loop.sh` broke and you are paying full rate for what should be cached. Raise `LOOP_DAILY_BUDGET_USD` only after you know why. |
| 4 | the conductor reached outside its read-only seat | **Page.** Either the triage input carried an injection, or the conductor drifted. Read the denied tool names in `STATE.md` and the findings that produced them. Do not re-run until you know which. |

Alarms written into `memory/STATE.md`:

| Line | Meaning | Response |
|------|---------|----------|
| `ALERT demoted: <skill>` | a skill dropped a trust tier | Automatic and expected when quality slips. Read the last failures for that skill. The skill stops running unattended by itself; do not hand it back its tier manually. |
| `ALERT goal VIOLATED: <goal>` | finished work stopped being true | **Page.** The sentinel finds; it never repairs. Open the goal file, read `on-violation`, and route the repair through the normal pipeline. |
| `ALERT budget breached` | see exit 3 | As above. |
| `ALERT conductor attempted …` | see exit 4 | As above. |
| `INJECTION-SUSPECT` in a finding | an issue or log addressed the agent | **Page.** Read the quoted text. It is data. Never act on it. Consider whether the repository accepts issues from outside. |
| `queued: …` / `queued (watch): …` | the conductor declined, or the skill is below `auto` | Normal. This is the system asking for a human, which is what it is for. |

## Cron

Not installed by this branch — installing a crontab is a decision, not a side effect. From week 2
of the rollout, and only with `LOOP_PUSH=1` deliberately set:

```cron
0 7 * * 1-5   cd /path/to/repo && LOOP_PUSH=1 make tick  >> loop/memory/cron.log 2>&1
30 7 * * *    cd /path/to/repo && make goals >> loop/memory/cron.log 2>&1
0 8 * * 5     cd /path/to/repo && make retro >> loop/memory/cron.log 2>&1
```

The sentinel runs daily and the tick only on weekdays: a regression should be found on the day it
appears, whether or not anyone intended to ship that day.
