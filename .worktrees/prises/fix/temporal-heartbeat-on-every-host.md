# fix/temporal-heartbeat-on-every-host

- **Scope**: #510. On the Temporal backend, Laravel and Magento give the activity worker the no-op
  heartbeat sender, so heartbeat timeouts and cancellation delivery never work there. Wire
  `TemporalActivityHeartbeatSender` as Symfony does, and remove the activities page's "do not set a
  heartbeat timeout" note in the same PR.
- **Entries**: `src/DurableLaravel/DurableServiceProvider.php`, `src/DurableModule/Runtime/RuntimeFactory.php`,
  `src/DurableModule/etc/di.xml`, `documentation/user/activities/_index{,.fr}.md`, their tests.
- **Stacked on #493** (`chore/dead-code-core` @ e620651d), which touches the same provider, factory
  and pages. The PR waits for #493 to merge, then takes main in.
- **State**: in progress — arwen, worktree `.claude/worktrees/temporal-heartbeat`. Reviewer: antoine.
