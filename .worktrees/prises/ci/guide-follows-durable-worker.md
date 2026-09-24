# ci/guide-follows-durable-worker

- **Scope**: #363, second half. The `guide-follows` job drives the queues with `durable:worker`, the command the guide teaches since #436, instead of `messenger:consume`.
- **Entries**: `bin/guide-follows/run.php` only.
- **State**: in review — PR #471 (durable-48, lane D).
