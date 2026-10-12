# feat/debug-durable

- **Scope**: `debug:durable`, in the style of `debug:messenger`: without argument, the workflows the bundle registered (type, class, entry method); with a workflow type or class, its signals, queries and updates with their parameter types. Activities and the backend summary come in a later slice.
- **Entries**: `src/DurableBundle/Command/`, `src/DurableBundle/DependencyInjection/Compiler/WorkflowPass.php`, DI wiring, tests, `documentation/user`.
- **Overlap**: none (`change/workflow-repository` is an OpenSpec proposal, no code; `change/diagnostic-commands` is merged).
- **State**: in progress, red test first.
