# refactor/shared-cursor-and-timestamp

- **Scope**: #362 — the DBAL and Illuminate bridges share their run-page cursor and their stored
  timestamp parsing through two core classes; Illuminate's metadata `get()` gets DBAL's
  `is_array($payload)` guard. The `durable-resume-` lock literal is left as is.
- **Entries**: new `src/Durable/Observation/RunPageCursor.php`, new
  `src/Durable/Store/StoredTimestamp.php`, their unit tests. Once #499 and #476 have merged: the
  call sites in the DBAL and Illuminate run catalogues and event stores, and
  `IlluminateWorkflowMetadataStore`.
- **State**: in progress, new files only — the call sites wait on #499 and #476 — dave.
