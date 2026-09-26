# test/resume-lock-timer-in-one-process

- **Scope**: #417 — a resume that schedules its own timer (`FireWorkflowTimersMessage` with
  `DispatchAfterCurrentBusStamp`) in a single process must not wait for its own resume lock. The
  re-entrancy from #254 should already cover it; this pins it with a regression test on the real
  Messenger stack order.
- **Entries**: `tests/unit/Bridge/Dbal/SingleResumeLockMiddlewareTest.php`; the middleware only if
  the test turns red on main.
- **State**: in review — PR #550, alice. Reviewer: sirius.
