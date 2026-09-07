# The optional loops

Each loop carries its install condition at the top of its own file. **If you cannot state the
condition, uninstall the loop** — installing loops you cannot justify is how an autonomous system
bloats into an unauditable one.

Interval note: Claude Code's recurring `/loop` tasks expire seven days after creation, so anything
weekly belongs in cron, not in `/loop`.

## Installed

- **`retro.sh`** — install condition: always. Weekly. Reads the week's exhaust and proposes at
  most three changes. Proposals only; it never edits `CLAUDE.md` itself.

## Not installed, and why

These three are specified so that installing one later is a decision with a written trigger,
rather than a good idea someone had on a Friday.

- **Quorum** — install when `memory/dispatch.tsv` shows the conductor repeatedly waking up only to
  decide `stop`. Three cheap models read the triage output and vote; the conductor runs on a 2-of-3
  consensus. On a quiet repository this halves conductor spend or better. *Not installed: the
  dispatch log is empty. Revisit after 30 days of ticks.*

- **Ratchet** — install when a single number matters and must never rise. A goal whose predicate is
  a metric plus a direction; the walls are that the number never rises and no test breaks, with a
  timebox of three attempts before it reports what remains. *Not installed: no such number has been
  named for this repository yet. `psalm-baseline.xml`'s entry count is the obvious first candidate,
  and would pair with the never-widen-the-baseline law in `CLAUDE.md`.*

- **Red team** — install when the repository ships code daily. A breaker agent writes ONE failing
  test against yesterday's merged diffs, committed under `@redteam`; a builder agent makes it pass
  by fixing the code, never the test. The constitution's test-tampering law already blocks the
  cheat. *Not installed: this repository does not merge daily. Revisit if that changes.*
