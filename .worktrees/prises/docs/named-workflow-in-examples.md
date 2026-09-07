# docs/named-workflow-in-examples

- **Work item**: the Nexus and comparison guides show `#[AsWorkflow]` with no argument, and the
  attribute's constructor takes a required `string $name`. `WorkflowDefinitionLoader` calls
  `newInstance()` on it, so the example throws `ArgumentCountError` at load; the loader's
  short-name fallback only covers a missing attribute, which `workflows/` already states
  correctly. Same blocks name `BillingServed::class` in `#[AsNexusServiceHandler]` while the
  Symfony demonstration and `laravel/config/durable.php` both name the complete contract.
- **Entry points**: `documentation/user/nexus/_index.md`, `documentation/user/comparison/_index.md`
  and their `.fr.md` twins. Code blocks only, no prose, no source change.
- **State**: in progress.
