# feat/phpstan-nondeterminism-reachability

- **Scope**: second half of the clock rule (#1030 merged): follow the call stack with a PHPStan collector, so a clock read in a helper that a `#[AsWorkflow]` class calls is reported at the workflow's call, with the chain. Replaces `NondeterministicCallRule` with its depth-0 case.
- **Entries**: `src/DurablePhpstan/`, `tests/unit/DurablePhpstan/`, README and UPGRADE entry of the package.
- **Overlap**: none found.
- **State**: in progress, red test first.
