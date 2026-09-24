# fix/bounded-execution-trace

- **Scope**: #336 — `DurableExecutionTrace` keeps the last 2 000 entries (one bound for HTTP,
  Messenger and Temporal workers); `ResetDurableProfilerListener` and its registration deleted.
- **Entries**: `src/DurableBundle/Profiler/DurableExecutionTrace.php`,
  `src/DurableBundle/EventListener/ResetDurableProfilerListener.php`, `DurableExtension`'s profiler
  registration, their tests.
- **State**: in review — PR #489, durable-7f (lane B).
