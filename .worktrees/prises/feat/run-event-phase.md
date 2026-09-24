# feat/run-event-phase

- **Scope**: #261 (part of #332) — `WorkflowRunEvent` carries a typed `phase` (requested, started,
  failed, settled) and the attempt number, set by the journal and Temporal readers. Panels stay
  with lanes B (profiler) and D (plugin, Magento).
- **Entries**: `src/Durable/Observation/`, `src/Bridge/Temporal/Store/TemporalRunHistoryReader.php`,
  their tests.
- **State**: in review — PR #483, durable-50 (lane A).
