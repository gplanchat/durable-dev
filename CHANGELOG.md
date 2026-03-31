# Changelog

## [Unreleased] — API breaks (Temporal parity)

### Fixed

- **Splitsh (CI)** — remplacement de `${{ runner.home }}` (contexte inexistant → `/.local/bin`, échec `mkdir`) par **`$HOME`** / **`~`** pour les chemins et le cache **actions/cache**.
- **Splitsh (CI)** — compilation de **libgit2 v1.5.2** dans `$HOME` (git2go v34 incompatible avec `libgit2-dev` du runner) ; cache combiné `splitsh-lite` + `libgit2-install`, **`LD_LIBRARY_PATH`** pour l’exécution.

### Added

- **Event store** — `EventStoreInterface::countEventsInStream()` ; implémentations **DBAL** (`COUNT(*)`) et **in-memory** ; **TemporalJournalEventStore** (itération du flux).
- **Bundle (profiler)** — trace des dispatches **`WorkflowRunMessage`** (`WorkflowRunDispatchProfilerMiddleware` + **RegisterWorkflowDispatchProfilerMiddlewarePass**), snapshots métadonnées / nombre d’événements dans le collecteur, template Twig avec branches explicites par `kind`.
- **GitHub Actions — Splitsh** — workflow `.github/workflows/splitsh.yml` : **restore/save** cache du binaire **splitsh/lite** v2.0.0 (Go + libgit2), exécute `bin/splitsh-publish.sh` ; push HTTPS optionnel via secret **`SPLITSH_PUSH_TOKEN`** ; **[ADR017](documentation/adr/ADR017-splitsh-ci-and-satellite-pushes.md)**.
- **ADR016** — [Dedicated DBAL connection and unbuffered reads](documentation/adr/ADR016-dedicated-dbal-connection-and-unbuffered-reads.md): `durable.dbal_connection`, alias `durable.dbal.connection`, options PDO MySQL pour flux non bufferisés.
- **Docker** — `compose.yaml` (Temporal `auto-setup`, UI, PostgreSQL) ; image **`docker/php/Dockerfile`** (PHP 8.2, grpc 1.57, pcov) avec profil Compose **`php`** ; `.dockerignore` ; **`compose.override.example.yaml`** ; job CI **`docker-compose-stack`** (démarrage + attente 7233 / UI 8088).
- **Infection** — `infection/infection` en `require-dev`, `infection.json.dist`, scripts Composer `infection`, `infection:unit`, `infection:functional`, `infection:unit-fast` ; PHPUnit découpé en suites **unit** / **functional** / **integration** / **e2e** (exécution `composer test` sans doublon entre suites).
- **ADR012** — `activityStub`, PSR-6 activity contract cache, Symfony **`ActivityContractCacheWarmer`**, and **`gplanchat/durable-phpstan`** (`ActivityStubMethodsClassReflectionExtension`).
- **`Awaitable` @template `TValue`** — enables **`Awaitable<R>`** in PHPStan for stubbed activity calls.
- **Bridge Temporal** — DSN unique **`temporal://`** via **`TemporalTransportFactory`** ; journal (`TemporalJournalTransport`) vs applicatif (`TemporalApplicationTransport` + **`inner`**) selon `purpose` / `inner` (schémas `temporal-journal://` / `temporal-application://` encore acceptés, normalisés).
- **Bundle** — **`ActivityRunHandler`** (`ActivityMessageProcessor` via Messenger `from_transport`).

### Changed (breaking)

- **Bundle** — suppression du paramètre de configuration **`durable.distributed`** : le bundle suppose toujours des workflows / activités via Messenger (reprise, métadonnées DBAL selon `workflow_metadata`, etc.). Retirer la clé YAML et toute injection **`%durable.distributed%`**.

### Changed (tooling)

- **README** — Quick start (~3 min), more approachable tone; activities with **`#[AsDurableActivity]`** (formerly `AsDurableActivityHandler`); install **`gplanchat/durable-bundle`**.
- **Layout** — source unique : **`src/Durable/`**, **`src/DurableBundle/`**, **`src/DurablePhpStan/`**, **`src/DurablePsalmPlugin/`** (chacun avec `composer.json` pour splitsh) ; tests sous **`tests/unit|functional|integration|e2e/`** avec namespaces **`unit|functional|integration|e2e\\Gplanchat\\…`**.
- **Bundle** — **`ActivityHandlerPass`**: compile-time activity registration on **`ActivityExecutor`** using contract **interfaces** (`interface_exists`, not only `class_exists`).
- **Documentation** — **[PRD005](documentation/prd/PRD005-symfony-empty-project-recipe.md)** (Symfony project recipe / quick start).
- **`symfony/` app** — sample contracts as **interfaces** (`Greeting`, `Echo`, `Tick`); auto-tagged handlers; no more **`DurableSamplesConfigurator`** or manual **`ActivityExecutor::register()`** calls.
- **PHPUnit** — `@coversNothing` in docblocks replaced by `#[CoversNothing]` on affected E2E / helper tests (end of *PHPUnit test runner deprecations* with `composer test`).
- **`symfony/` app** — dependencies **`gplanchat/durable`** + **`gplanchat/durable-bundle`** via **path** repos → `../src/Durable` and `../src/DurableBundle`.

### Renamed

- **Bundle attribute** — `Gplanchat\Durable\Bundle\Attribute\AsDurableActivityHandler` → **`AsDurableActivity`**.

### Removed

- **ReactPHP bridge** — `Gplanchat\Durable\Bridge\ReactPromise` and its unit test removed; `react/promise` is no longer a direct dependency of this package (it may still appear transitively, e.g. via php-cs-fixer).
- **`src/functions.php`** — File removed. The Composer `files` autoload entry for it was removed from `composer.json`.
- **Global helpers** `await()`, `parallel()`, `all()`, `any()`, `race()`, `delay()`, `timer()`, `sideEffect()`, `execute_child_workflow()`, `wait_signal()`, `wait_update()`, `async()` — These helpers no longer exist. Use **`WorkflowEnvironment`** methods instead.
- **Workflow handler signature** — Callables no longer receive `(ExecutionContext $ctx, ExecutionRuntime $rt)` but **`(WorkflowEnvironment $env)`**.
- **Workflow registration** — `WorkflowRegistry::register(string, callable)` removed. Use `WorkflowRegistry::registerClass(WorkflowClass::class)` or the Symfony `durable.workflow` tag.
- **Bridge Temporal** — `TemporalJournalSettings`, `TemporalApplicationSettings`, `TemporalJournalTransportFactory`, `TemporalApplicationTransportFactory` removed; use **`TemporalConnection`** + **`TemporalTransportFactory`** (single **`temporal://`** DSN, `purpose` / `inner` for journal vs application).
- **Bundle** — Console command **`durable:activity:consume`** removed; distributed mode with **`activity_transport.type: messenger`** uses **`ActivityRunHandler`** and **`messenger:consume`** on the activity transport only (breaking change, no deprecation period).

### Changed

- **Workflow entry point** — All operations (await, timer, activity, childWorkflow, waitSignal, waitUpdate, etc.) go through injected **`WorkflowEnvironment`**.
- **Activities** — Recommended API: `$env->activityStub(ActivityInterface::class)->methodName(...)` instead of `$env->activity('name', $payload)`.
- **Child workflows** — Recommended API: `$env->childWorkflowStub(ChildWorkflowClass::class)->run(...)` instead of `$env->executeChildWorkflow('WorkflowType', $input)`.

### Added

- **`DurableChildWorkflowFailedException`** — Optional fields `workflowFailureKind`, `workflowFailureClass`, `workflowFailureContext` (aligned with `ChildWorkflowFailed` / parent log replay).
- **Sample `symfony/` app** — default `vendor` under **`symfony/vendor/`** (no forced `vendor-dir` outside the repo); optional fallback to `../../durable-symfony-vendor` in `load_autoload_runtime.php` / bootstrap / `bin/phpunit` if present. **`composer test`**: schema + samples (**Greeting**, **ParallelGreeting**, **ParentCallsEchoChild**, **TimerThenTick**, **SideEffectRandomId**). **CI**: **`symfony-sample`** job.

- **`WorkflowEnvironment`** — Single façade per execution.
- **`#[Workflow]`, `#[WorkflowMethod]`** — Attributes for workflow classes.
- **`#[Activity]`, `#[ActivityMethod]`** — Attributes for activity contracts.
- **`#[SignalMethod]`, `#[QueryMethod]`, `#[UpdateMethod]`** — Attributes documenting signal/query/update channels.
- **`ActivityStub`**, **`ChildWorkflowStub`** — Typed proxies.
- **`WorkflowDefinitionLoader`** — Load workflows by class.
- **`ActivityContractResolver`** — Resolve activity metadata (PSR-6 cache).
- **`ActivityContractCacheWarmer`** — Preload contracts on Symfony warmup.
- **`durable.workflow` tag** — Automatic workflow registration via the Symfony container.
