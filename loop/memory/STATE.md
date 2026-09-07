# STATE

The conductor reads this file every tick and never trusts its memory of it. Anything a human needs
the loop to know goes here. Lines beginning `ALERT` are pages — see `loop/contract.md`.

## Standing notes

- The loop opens draft PRs on `loop/*` branches only. Merging to `main` is a human decision.
- `loop/*` branches are exempt from the prise registry (`.worktrees/prises/`) — see WA007.
- Local `vendor/` must match `composer.lock`, or the gate fails before it judges anything.

## Queue

(empty)
