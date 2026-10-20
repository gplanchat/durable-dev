# fix/structured-wait-reason

- **Scope**: #850, the stored wait carries a kind and its parameters (a `Wait` value, JSON in the same column and memo), so the dashboards translate it and format the timer date; rows already written stay English prose.
- **Entries**: src/Durable/Observation/ (Wait, WaitReason, WorkflowRunDescription, RunDashboard), the lifecycles that record a wait, the Sylius and Filament translations, UPGRADE.md.
- **Overlap**: UPGRADE.md at the end of Unreleased; the dashboard translation files.
- **Session**: interactive, 2026-10-20.
- **State**: in progress.
