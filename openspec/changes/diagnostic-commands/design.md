## Context

Read from `main` at 8ce8d214.

**Health, per host.**

- Symfony: `src/DurableBundle/Command/HealthCommand.php` reads `WorkerPresence`, which asks
  `TemporalTaskQueueProbe` who polls each role's task queue, and fails when a role has had no poller
  for `WorkerPresence::SILENCE_SECONDS` (120). The command is registered in
  `EventStores::registerTemporalMirrorInfrastructure()`, so it does not exist on the in-memory and
  DBAL backends. It does not check that the cluster answers: an unreachable cluster shows up as an
  error per role.
- Magento: `src/DurableModule/Console/Command/HealthCommand.php` calls
  `RuntimeFactory::catalog()->checkHealth()` first, prints the ephemeral case (no DSN) and exits 0,
  exits 1 when the cluster does not answer, then checks the `journal` and `activity` roles through
  `RuntimeFactory::workers()`.
- Laravel: no health command. `DurableServiceProvider` (line 361) registers
  `durable:temporal-worker` and `durable:nexus-worker` only.

**A shared core already exists for part of it.** `WorkflowRunCatalogInterface::checkHealth()`
returns a `BackendHealth` (reachable, unreachable, or ephemeral) on the four backends
(`InMemoryWorkflowRunCatalog`, `DbalWorkflowRunCatalog`, `IlluminateWorkflowRunCatalog`,
`TemporalWorkflowRunCatalog`). The DBAL probe runs the platform's dummy select; the Temporal probe
lists one execution of the namespace, so it also fails when the namespace does not exist.

**Capabilities.** No backend declares what it supports. The capability matrix in
`documentation/user/backends/_index.md` (and its FR twin) is written by hand. At run time, a
backend that cannot honour a command throws: `UnsupportedByBackendException::forMethod()` (DUR051),
`NexusUnsupportedByBackendException::forBackend('journal')` in `EventStoreCommandBuffer` for a
Nexus call, and `NexusUnsupportedByBackendException::forHandlerOn()` in
`NexusOperationRegistry::register()` and `registerFulfilment()` for a Nexus handler.

**Server version.** `TemporalWorkflowRunCatalog::serverHasStartsWith()` already calls
`GetSystemInfo` once, compares `ltrim($version, 'v')` with 1.23.0, and treats an empty version as
recent. It is the one version check on `main`.

**Search attributes.** `DurableSearchAttributes` names the two Keyword attributes Durable writes
(`DurableWorkflowName`, `DurableExecutionId`) when `TemporalConnection::$searchAttributes` is on. Its
docblock states that a server fails a start naming an attribute that is not registered. Nothing
checks the registration before that start.

**Nexus endpoints.** A workflow calls an operation through `WorkflowEnvironment::nexusStub()`
(line 585), inside the workflow method's body. Nothing checks that the endpoint it names exists on
the cluster.

**DUR054.** Symfony: `WarnOnSharedJournalConnectionPass` registers
`WarnOnSharedJournalConnectionListener` when the DBAL journal sits on the default connection, and
the listener logs a warning when a Messenger worker starts. Laravel:
`DurableServiceProvider::warnWhenTheJournalSharesTheDefaultConnection()` (line 157) logs it when
the console boots. DUR054 decision 6: warned about, never refused. Magento has no SQL journal on
`main`; DUR056 (#741) is open.

**RPCs the bridge wraps.** `WorkflowServiceClientInterface` wraps WorkflowService RPCs only. Listing
search attributes and Nexus endpoints goes through OperatorService, which the bridge does not call
today.

## Goals / Non-Goals

**Goals**

- One command set, one output format and one exit code contract on Symfony, Laravel and Magento.
- One declaration per backend of what it supports, read by the commands and by the documentation
  generator.
- A finding an operator can act on: what is wrong, where, and the page that explains it.

**Non-Goals**

- Changing what a backend supports.
- Turning a DUR054 warning into a refusal at boot.
- A dashboard view of the findings.

## Decisions

These were made on 2026-10-01 and are recorded, not reopened.

### Three commands on a shared core

`durable:capabilities`, `durable:health` and `durable:doctor` run the same core on the three hosts.
A host command builds the core from its container and passes it the options; it adds no check of
its own. The output, the JSON shape and the exit code are the same on every host, so a CI script
written against one host runs against another.

`durable:health` keeps the name the Symfony and Magento commands have today and every check they
make. On Symfony it is registered for every backend, not only Temporal.

`durable:doctor` runs the two others, then the configuration and code checks, and reports all the
findings in one output. It does not stop at the first one.

### A backend declares its capabilities in code

Each backend declares a static matrix: for each capability of a closed list, supported, not
supported, or supported from a minimum server version. The first list is the rows of today's
documentation matrix plus the rows the decision names: activities, retries and timeouts; timers;
signals; updates; queries; child workflows; `ParentClosePolicy` cascade; continue-as-new;
cancellation; survival of a process restart; search attributes; cron schedules; Nexus call; Nexus
serve; a child's summary and details.

`durable:capabilities` prints the configured backend's matrix. When the backend has live probes
(Temporal), the static entry is refined by what the server reports: "updates, from 1.21" becomes
"updates: not supported by this server (1.20.3)".

The documentation matrix in `backends/_index.md` and `backends/_index.fr.md` is generated from the
four declarations, between markers, by a script in `bin/`. A test fails when the committed table
differs from the generated one.

### What each command checks

| Check | capabilities | health | doctor |
|---|---|---|---|
| Static matrix of the configured backend | yes | | yes |
| Server version against each capability's minimum (`GetSystemInfo`) | yes | | yes |
| Backend reachable (`checkHealth()`) | | yes | yes |
| Each worker role polls its task queue (Temporal) | | yes | yes |
| Durable's search attributes registered, when enabled | | | yes |
| Each Nexus endpoint a workflow names exists | | | yes |
| Journal on a dedicated connection (DUR054) | | | yes |
| A declared workflow uses a capability the backend does not support | | | yes |

### A finding carries a severity, a subject and a link

Every check produces findings with a stable code (`server.version.updates`), a severity, the
subject (a backend, a role, a workflow class, an attribute name), a sentence, and the URL of the
documentation page that explains it. The table and the JSON render the same findings. The JSON
carries a format version so a CI script can detect a shape change.

### Output and exit code: one contract for the three commands

A readable table by default, `--format=json` on all three commands. A finding is an error or a
warning. The three commands share one exit code contract:

- An error gives a non-zero exit code. Without errors, the exit code is 0, warnings included.
- `--fail-on=warning` makes warnings give a non-zero exit code too.
- The journal on the application's default connection (DUR054) is a warning. This matches DUR054
  decision 6, "warned about, never refused".

### `durable:doctor` requires the compiled container

The doctor reads the workflow list the host has already built. It does not build that list itself.

- Symfony: the container cache must be warmed (`bin/console cache:warmup`).
- Magento: the DI must be compiled (`bin/magento setup:di:compile`).
- Laravel: the `WorkflowRegistry` singleton is resolved the way it is today, on first use.

When the container or DI is not compiled, `durable:doctor` fails with a message that names the
command to run.

### An unreachable server

- `durable:health` fails. Reporting a service that does not answer is its job.
- `durable:capabilities` falls back to the static matrix and marks each live row "not checked".
- `durable:doctor` marks each probe that needs the server "not checked", and reports one overall
  error for the unreachable server rather than one error per probe.

### The code scan reads declarations, Nexus calls included

`durable:doctor` reads the workflows the host registers and their attributes by reflection. It
matches what they declare against the backend's matrix. Examples: a Nexus handler
(`#[AsNexusServiceHandler]`, `#[FulfilsNexusOperation]`) on a backend without Nexus serve, or a
signal, query or update method on a backend that does not support it.

The scan does not execute workflow code and does not parse method bodies. A Nexus call is a
declaration through the `#[NexusOperations]` parameter attribute of
`inject-nexus-and-child-workflow-stubs` (PR #781). That change is implemented first, and the
doctor checks Nexus calls from its first version: a workflow parameter carrying
`#[NexusOperations]` on a backend without Nexus call is an error.

Reflection reads class, method and parameter attributes on every host. Magento's object manager
does not resolve parameter attributes for injection, but that limit does not apply to the scan:
the scan only reads attributes and injects nothing.

## Probed and assumed

**Read from code, not probed:** everything in Context.

**From open PRs, not merged:** updates need Server 1.21, and `frontend.enableUpdateWorkflowExecution`
from 1.21 to 1.24 (#877); a child's summary and details need 1.25 (#855). The minimums enter the
matrix when those PRs merge, with the PR as their source.

**Assumed, to probe before the requirement hardens (tasks, section 0):**

1. `GetSystemInfo` returns a parseable `server_version` on Server 1.25.2 (the dev server) and 1.32
   (CI). The existing check treats an empty version as recent; for a minimum check that default
   reports nothing on a server too old to answer, which is the case the check exists for.
2. Whether `frontend.enableUpdateWorkflowExecution` is observable without sending an update. If the
   only way to know is to attempt one, the probe writes to the cluster, and the doctor reports the
   flag as "not checked" with the version range instead.
3. OperatorService `ListSearchAttributes` and `ListNexusEndpoints` answer on the same connection,
   with the same credentials, as WorkflowService. On Temporal Cloud the endpoint list may need a
   different API or permission.
4. A Nexus endpoint named by a workflow is found by name in that list.

## Open points for the owner

None. The four points the first draft listed (exit code per severity, a compiled container for the
code scan, Magento's limits, an unreachable server) were decided on 2026-10-01 and are recorded
under Decisions.

**Still to verify while implementing:** how `durable:doctor` detects an uncompiled container on
Symfony and an uncompiled DI on Magento. On Symfony the workflow list is built by `WorkflowPass`
at container compilation. On Magento, `RuntimeFactory` builds a new `WorkflowRegistry` per call
(PR #781, design, Context), so the check that `setup:di:compile` has run has to be found on that
host.

## Risks

- **A matrix that drifts from behaviour.** The declaration is static; a backend can declare a
  capability it fails at run time. The conformance suites are where a declaration is proven; each
  "supported" row names the suite that covers it, and a row without one is reported as unproven.
- **Probe cost in CI.** Each live probe is one RPC with the short gRPC timeout
  (`TemporalGrpcTimeouts::SHORT_US`). The doctor runs them in sequence; a CI job against an
  unreachable cluster waits for each timeout.
- **A doc link that breaks.** A finding's URL is part of the output contract. Moving a page then
  breaks a CI log; pages are pinned with Hugo aliases and `{#slug}` anchors, as for any published
  URL.
