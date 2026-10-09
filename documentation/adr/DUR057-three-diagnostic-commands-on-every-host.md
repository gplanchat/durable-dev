# DUR057: Three diagnostic commands on every host

## Status

Proposed. ADRs in `documentation/adr/` are supervised, so this one takes effect when the user
approves its text on its pull request.

The user made the decisions below on 2026-10-01. The OpenSpec change `diagnostic-commands`, draft
PR #885, carries their design, spec and tasks.

Related:
- [DUR051](DUR051-a-backend-refuses-what-it-cannot-honour.md): a backend that cannot perform an
  operation throws an exception that names it. The commands below report the same gaps before an
  execution reaches them.
- [DUR054](DUR054-the-journal-does-not-share-the-applications-connection.md): the journal (the
  append-only sequence of events that records everything an execution decided and received; see the
  [glossary](../user/glossary/_index.md)) on the application's default connection is warned about,
  never refused. Decision 6 keeps that severity.
- [DUR056](DUR056-magento-journals-through-its-own-db-layer.md), the Magento SQL journal, open in
  #741.

## Context

An operator who deploys Durable has no command that answers three questions before the first
execution fails: what the configured backend supports, whether the services it depends on answer,
and whether the application's workflows use something the backend does not support. On `main`
(8ce8d214):

- **Health differs per host.** On Symfony, `src/DurableBundle/Command/HealthCommand.php` exists only
  on Temporal: `EventStores::registerTemporalMirrorInfrastructure()` registers it
  (`src/DurableBundle/DependencyInjection/Loader/EventStores.php`). It checks that each worker role
  polls its task queue, through `WorkerPresence`, and does not check that the cluster answers. On
  Magento, `src/DurableModule/Console/Command/HealthCommand.php` calls
  `RuntimeFactory::catalog()->checkHealth()` first, then checks the worker roles. On Laravel,
  `DurableServiceProvider` registers `durable:temporal-worker` and `durable:nexus-worker` and no
  health command.
- **A shared probe already exists.** `WorkflowRunCatalogInterface::checkHealth()` returns a
  `BackendHealth` (reachable, unreachable or ephemeral) on the four backends.
- **No backend declares what it supports.** The capability matrix in
  `documentation/user/backends/_index.md` and its French twin is written by hand, and nothing
  checks it against the code. PR #782 found that its "Signals, updates, queries" row claims a
  parity the journal backends do not have.
- **One server version check.** `TemporalWorkflowRunCatalog::serverHasStartsWith()` calls
  `GetSystemInfo` and compares the version with 1.23.0 for one query feature. Open PRs document two
  more minimums: updates need Server 1.21 (#877), a child's summary and details need 1.25 (#855).
- **Search attributes and Nexus endpoints are not checked.** `DurableSearchAttributes` names the two
  attributes Durable writes, and a start that names an unregistered one fails on the server. Nothing
  checks that the Nexus endpoint a workflow names through `WorkflowEnvironment::nexusStub()` exists.
- **DUR054 is a log line.** `WarnOnSharedJournalConnectionPass` on Symfony and
  `DurableServiceProvider::warnWhenTheJournalSharesTheDefaultConnection()` on Laravel log a warning
  when the journal sits on the default connection.

An operator learns about each of these gaps when an execution fails in production.

## Decision

1. **Three commands, the same on Symfony, Laravel and Magento.** `durable:capabilities`,
   `durable:health` and `durable:doctor` run under `bin/console`, `artisan` and `bin/magento`. One
   core in `src/Durable` produces the findings. A host command builds that core from its container
   and adds no check of its own. For one configuration, the three hosts print the same findings and
   exit with the same code.
2. **`durable:capabilities`** prints what the configured backend supports.
3. **`durable:health`** checks the services the configuration depends on: the backend answers and,
   on Temporal, each worker role polls its task queue. It keeps the name and every check of today's
   Symfony and Magento commands, exists on every backend, and comes to Laravel.
4. **`durable:doctor`** runs the checks of `durable:health` and `durable:capabilities`, then checks
   the application's configuration and its declared workflows against the backend. It reports every
   finding, not only the first. Each finding carries a stable code (such as
   `server.version.updates`), a severity, its subject and the URL of the documentation page that
   explains it.
5. **Each backend declares a static capability matrix in code.** For each capability of a closed
   list, the matrix says supported, not supported, or supported from a minimum server version. It
   is the single source. A script in `bin/` generates the documentation's capability matrix, EN
   and FR, from the four declarations, and a test fails when the committed table differs.
6. **Live probes refine the matrix.** On Temporal: the server version from `GetSystemInfo` against
   each capability's minimum (updates from 1.21, for example), the registered search attributes when
   Durable writes them, and the Nexus endpoints the workflows name. On the SQL backends: the
   journal's dedicated connection (DUR054). A journal on the application's default connection is a
   warning, as DUR054 decision 6 sets.
7. **One output and one exit code contract for the three commands.** A table by default,
   `--format=json` with a format version. A finding is an error or a warning. Only an error gives a
   non-zero exit code; `--fail-on=warning` makes warnings give one too.
8. **`durable:doctor` reads the workflow list the host has built** and never builds it itself. It
   requires a warmed container on Symfony (`bin/console cache:warmup`) and a compiled DI on Magento
   (`bin/magento setup:di:compile`); on Laravel, `WorkflowRegistry` resolves as it does today. When
   the container or the DI is not compiled, the doctor fails with a message that names the command
   to run.
9. **An unreachable server is reported once.** `durable:health` fails. `durable:capabilities` falls
   back to the static matrix and marks each live row "not checked". `durable:doctor` marks each
   probe that needs the server "not checked" and reports one overall error for the unreachable
   server. A check the command cannot perform is never reported as passed.
10. **The code scan reads declarations and executes nothing.** It reads the class, method and
    parameter attributes of the registered workflows by reflection: a Nexus handler on a backend
    without Nexus serve, a signal, query or update method on a backend that does not support it. A
    Nexus call is read from the `#[NexusOperations]` parameter attribute of
    `inject-nexus-and-child-workflow-stubs` (PR #781), so #781 is implemented first and the doctor
    checks Nexus calls from its first version. Magento's object manager does not resolve parameter
    attributes for injection; the scan injects nothing, so that limit does not apply to it.

## Consequences

- An operator can gate a deployment on `durable:doctor --format=json` in CI, and a script written
  against one host runs against the other two.
- The documentation matrix can no longer drift from the code: changing a backend's declaration
  without regenerating the table fails the tests.
- A declaration can still claim a capability the backend fails at run time. Each "supported" row
  names the conformance suite that proves it, and a row without one is reported as unproven.
- Search attributes and Nexus endpoints go through OperatorService, which the bridge does not call
  today. Four assumptions are probed before the requirements harden (#885, tasks section 0):
  `GetSystemInfo` returns a parseable version on Server 1.25.2 and 1.32; whether
  `frontend.enableUpdateWorkflowExecution` is observable without sending an update; OperatorService
  answers on the WorkflowService connection and credentials; an endpoint is found by name.
- Each live probe is one RPC with the short gRPC timeout. Against an unreachable cluster, the doctor
  waits for each timeout in turn.
- A finding's URL is part of the output contract. A page it links to is pinned with Hugo aliases
  and `{#slug}` anchors, as any published URL.
- How the doctor detects an uncompiled DI on Magento is found during implementation:
  `RuntimeFactory` builds a new `WorkflowRegistry` per call.
- No BC break. The existing `durable:health` commands keep their name and their checks.

## Alternatives considered

- **One command, by extending `durable:health`.** Health answers "do the services answer now", a
  question an orchestrator asks every few seconds. Adding the configuration and code checks to it
  makes that probe slow and mixes a liveness check with a deployment gate.
- **A single `durable:doctor`.** An operator who wants to know what a backend supports, before any
  application code exists, would have to run the full diagnosis. Keeping `capabilities` and
  `health` separate lets each answer one question, and the doctor runs both.
- **A capability matrix in the documentation only.** That is today's state, and PR #782 already
  found a row that does not match the code. A matrix the commands cannot read also cannot drive
  the code scan.
- **The doctor building the workflow list itself.** It would duplicate `WorkflowPass` on Symfony and
  the registry wiring on Magento and Laravel, and could report on a list that differs from the one
  the application runs. Requiring the compiled container costs one command the error message names.
- **Scanning without #781.** A Nexus call is today a `nexusStub()` call inside a method body. The
  scan would have to parse method bodies or execute workflow code, or ship without checking Nexus
  calls. Waiting for #781 turns the call into an attribute that reflection reads.

## Related

- PR #885, OpenSpec change `diagnostic-commands`: proposal, design, spec and tasks.
- PR #781, `inject-nexus-and-child-workflow-stubs`: the `#[NexusOperations]` parameter attribute.
- #877 and #855: the minimum server versions of updates and of a child's summary and details.
- PR #782, `workflow-repository`: the matrix row that does not match the code.
