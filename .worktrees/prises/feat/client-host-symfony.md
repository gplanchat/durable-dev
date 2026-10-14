# feat/client-host-symfony

- **Scope**: #973, #974: task 4.1 of the `workflow-repository` change. Symfony (and Sylius through it): repositories autoconfigured from `#[AsWorkflowRepository]`, refused when their workflow is not registered; the `#[RepositoryFor]` compiler pass; `WorkflowClientPort` bound on every backend.
- **Entries**: `src/DurableBundle/`, `symfony/`, `tests/`
- **State**: in progress
