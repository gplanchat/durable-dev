## Why

An operator who deploys Durable has no command that answers three questions before the first
execution fails: what the configured backend supports, whether the services it depends on answer,
and whether the application's own workflows ask for something the backend does not support.

What exists today on `main` (8ce8d214), read from the code:

| | Symfony | Laravel | Magento |
|---|---|---|---|
| `durable:health` | Temporal only: registered in `EventStores::registerTemporalMirrorInfrastructure()` (`src/DurableBundle/DependencyInjection/Loader/EventStores.php`, line 286). It checks worker pollers, not reachability. | none: `DurableServiceProvider` registers only the two worker commands (line 361) | both backends (`src/DurableModule/Console/Command/HealthCommand.php`): reachability through the run catalogue, then worker pollers; on memory it reports that no worker exists |
| What the backend supports | the hand-written table in `documentation/user/backends/_index.md` ("Capability matrix") | same | same |
| Server version checked | once, inside the run catalogue, for one query feature (`TemporalWorkflowRunCatalog`, line 60: `STARTS_WITH` needs 1.23) | same | same |
| Journal on a shared connection (DUR054) | a log warning when a Messenger worker starts | a log warning when the console boots | no SQL journal on `main` (DUR056 is open in #741) |
| A workflow that uses what the backend does not support | fails at the call (`EventStoreCommandBuffer`, `NexusUnsupportedByBackendException`) or, for a Nexus handler, when the container is built (`NexusOperationRegistry`) | same | same |

The capability table in the documentation is maintained by hand and nothing checks it against the
code. The `workflow-repository` change (PR #782) found that its "Signals, updates, queries" row
claims parity the journal backends do not have. Two server requirements are being documented in
open PRs: updates need Server 1.21, and `frontend.enableUpdateWorkflowExecution` from 1.21 to 1.24
(#877); a child's summary and details need 1.25 (#855). An operator learns about either one when an
execution fails in production.

## What Changes

Decisions made on 2026-10-01, recorded here and not reopened by this change:

- **Three commands, the same on every host.** `durable:capabilities`, `durable:health` and
  `durable:doctor`, under `bin/console`, `artisan` and `bin/magento`. One shared core produces the
  findings; each host only wires it. The output is the same on the three hosts (parity rule).
- **`durable:capabilities`** prints what the configured backend supports.
- **`durable:health`** checks the services the configuration depends on. It keeps what the Symfony
  command checks today (each worker role polls its Temporal task queue), extends it to the other
  services and backends, and comes to Laravel. Magento's command moves onto the shared core.
- **`durable:doctor`** runs `durable:health` and `durable:capabilities`, then checks the
  configuration of the application. Each finding links to the documentation page that explains it.
- **What is checked:**
  1. A static capability matrix that each backend declares in code (signals, updates, queries,
     Nexus call and serve, timers, continue-as-new, search attributes, cancellation, and the rest of
     the documented rows). The documentation's capability matrix is generated from it.
  2. Live probes: the Temporal server version (`GetSystemInfo`) against the minimum of each
     capability that has one; the registered search attributes; the Nexus endpoints; the journal on
     a dedicated connection (DUR054).
  3. The application's code: a declared workflow that uses a capability the backend does not
     support, found from its attributes and declarations.
- **Output**: a readable table by default, `--format=json` for machines, and a non-zero exit code
  when a gap is found, so a CI job can gate a deployment on it.

## Not in scope

- Fixing what the commands find. A gap reported here is closed by its own change.
- A dashboard panel. The dashboards already show worker presence; they may consume the same core
  later.
- Wiring the commands into the project's own CI: `.github/workflows/` is changed by a human.

## Impact

- `src/Durable`: the capability vocabulary, the finding model, the shared runner and its two
  renderers (table, JSON).
- Each backend (`src/Durable` in-memory, `src/Bridge/Dbal`, `src/Bridge/Illuminate`,
  `src/Bridge/Temporal`): its declared capabilities. Temporal also gains its live probes.
- `src/DurableBundle`, `src/DurableLaravel`, `src/DurableModule`: three commands each. The
  existing Symfony and Magento `durable:health` keep their name and their current checks.
- `documentation/user/backends/_index.md` and `_index.fr.md`: the matrix becomes generated. One new
  page per finding that has no page to link to yet, EN and FR.
- Benches: each one runs `durable:doctor` in its own test suite.
