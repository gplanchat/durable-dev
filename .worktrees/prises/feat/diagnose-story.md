# feat/diagnose-story

- **Scope**: `durable:execution:diagnose --story`: the journal as one line per event, ending with what the run waits on, read from the run catalog (`WorkflowRunDescription::waitingOn`, set at each suspension). No journal change, no ADR: the label of an `await(fn, label: 'signal approve')` is already recorded there.
- **Entries**: `src/DurableBundle/Command/DiagnoseExecutionCommand.php`, `src/Durable/Observation/` (narrator), DI wiring, tests, `documentation/user`.
- **Overlap**: none (`feat/reserved-memo-keys` is about ChildWorkflowOptions; `diagnostic-commands` is merged).
- **State**: in progress, red test first.
