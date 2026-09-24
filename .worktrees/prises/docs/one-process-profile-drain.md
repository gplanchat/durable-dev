# docs/one-process-profile-drain

- **Scope**: #444 — the guide says the consumer commands belong to the several-processes profile,
  and how the one-process profile is drained (in the test, or `--no-reset`), EN and FR. Box 2,
  decided "refuse": a `WorkerStartedEvent` listener in the bundle refuses to run a worker that
  consumes a Durable `in-memory://` transport with services reset after each message (both
  `durable:worker` and `messenger:consume` reach it), with an UPGRADE line.
- **Entries**: `documentation/user/getting-started/_index{,.fr}.md`, a new listener under
  `src/DurableBundle/EventListener/`, its wiring in `DurableExtension`, its test, `UPGRADE.md`.
- **State**: in progress — vera; reviewers bob (docs), antoine (listener).
