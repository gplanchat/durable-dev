# feat/redact-diagnostic-payloads

- **Scope**: #335 — a payload redactor applied to the profiler panel and to
  `durable:execution:diagnose --json` (`--raw` restores raw output), the `?durable_execution` id
  format validated, each journal read once per request.
- **Entries**: `src/DurableBundle/DataCollector/`, `src/DurableBundle/Command/DiagnoseExecutionCommand.php`,
  a redactor next to them, their tests, the getting-started diagnose step (one paragraph, EN/FR).
- **State**: in progress — durable-7f (lane B).
