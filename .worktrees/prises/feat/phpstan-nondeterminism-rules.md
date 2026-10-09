# feat/phpstan-nondeterminism-rules

- **Scope**: PHPStan rules in DurablePhpstan that report clock and randomness reads reachable from a `#[AsWorkflow]` class: `time()` and friends, `new DateTime*` and subclasses (Carbon), `Psr\Clock\ClockInterface::now()/sleep()`, through the call stack (collector), exempting `$env->sideEffect()` closures.
- **Entries**: `src/DurablePhpstan/`, `tests/unit/DurablePhpstan/`, `documentation/user/` (rule reference).
- **Overlap**: none found (feat/phpstan-activities-parameter-hint is a different rule).
- **State**: in progress, red test first.
