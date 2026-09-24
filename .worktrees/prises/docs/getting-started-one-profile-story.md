# docs/getting-started-one-profile-story

- **Scope**: #368. One profile story in the getting-started guide (in-memory for tests, DBAL for local dev and production, Temporal with a cluster). `dispatchNewWorkflowRun()` is named as the backend-neutral entry point; `startAsync()` is marked Temporal-only.
- **Entries**: `documentation/user/getting-started/_index{,.fr}.md`, `documentation/user/options/_index{,.fr}.md`, `documentation/user/packages/_index{,.fr}.md`.
- **State**: in review — PR #472 (durable-48, lane D).
