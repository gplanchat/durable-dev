# Tasks

TDD throughout (WA002): the failing test first, in the same commit. Each commit under 200 changed
lines. A behaviour shared by the three hosts is tested once on the core and once per host through
the command, with the same expected output.

## 0. Decide and probe before building

- [ ] 0.1 The owner settles the four open points of `design.md`.
- [ ] 0.2 Draft the ADR: three commands on a shared core, a capability matrix declared by each
      backend, the exit code contract. The ADR is the owner's decision (DUR000).
- [ ] 0.3 Probe `GetSystemInfo` on Server 1.25.2 and 1.32: the shape of `server_version`, and what
      it returns when the server answers without one.
- [ ] 0.4 Probe whether `frontend.enableUpdateWorkflowExecution` is observable without sending an
      update, on 1.21 to 1.24 and on 1.25 or later.
- [ ] 0.5 Probe OperatorService `ListSearchAttributes` and `ListNexusEndpoints` on the dev server
      and on CI's server, over the bridge's transports (grpc, grpc-curl, http).
- [ ] 0.6 Record the answers in `design.md`, "Probed and assumed", keeping what was assumed.

## 1. Core

- [ ] 1.1 The closed list of capabilities and the matrix type: supported, not supported, or
      supported from a server version.
- [ ] 1.2 The finding: code, severity, subject, sentence, documentation URL.
- [ ] 1.3 The runner: runs the checks a command selects, collects every finding, computes the exit
      code from the contract settled in 0.1.
- [ ] 1.4 The table renderer and the JSON renderer, with a format version. Test: the same findings
      render to the same JSON whatever the host.
- [ ] 1.5 The health check over `WorkflowRunCatalogInterface::checkHealth()`: reachable,
      unreachable, ephemeral.
- [ ] 1.6 The code scan: reads the registered workflow classes and their attributes, and reports
      each declaration the backend's matrix marks as not supported.

## 2. Backends

- [ ] 2.1 In-memory, DBAL and Illuminate declare their matrix. Test: each row equals today's
      documentation table, row by row, before the table is generated.
- [ ] 2.2 Temporal declares its matrix with the minimum versions merged by #877 and #855.
- [ ] 2.3 Temporal's version probe, on `GetSystemInfo`, shared with
      `TemporalWorkflowRunCatalog::serverHasStartsWith()` so the bridge asks once.
- [ ] 2.4 Temporal's worker presence check, moved from `WorkerPresence` and
      `RuntimeFactory::workers()` into the core's health check.
- [ ] 2.5 Temporal's search attribute probe, when `TemporalConnection::$searchAttributes` is on.
- [ ] 2.6 Temporal's Nexus endpoint probe, for the endpoints the registered workflows name.
- [ ] 2.7 The DUR054 check on DBAL and Illuminate, reusing what `WarnOnSharedJournalConnectionPass`
      and `warnWhenTheJournalSharesTheDefaultConnection()` compare.

## 3. Hosts

- [ ] 3.1 Symfony (Sylius through it): `durable:capabilities`, `durable:doctor`, and
      `durable:health` registered on every backend. The existing worker messages are kept.
- [ ] 3.2 Laravel: the three commands, registered with the worker commands.
- [ ] 3.3 Magento: the three commands in `di.xml`; `durable:health` moves onto the core and keeps
      its ephemeral message.
- [ ] 3.4 A parity test per bench: the same configuration gives the same JSON on the three hosts.

## 4. Documentation, EN and FR

- [ ] 4.1 A script in `bin/` generates the capability matrix of `backends/_index.md` and
      `backends/_index.fr.md` between markers, and a test fails when the committed table differs.
- [ ] 4.2 A reference page for the three commands: options, JSON shape, exit codes, and how to gate
      a deployment on `durable:doctor`.
- [ ] 4.3 One page or anchor per finding code that has none, pinned with `{#slug}`.
- [ ] 4.4 `dashboard/run-not-progressing` and `dashboard/_index` mention `durable:health` on the
      three hosts, not only `bin/console`.
- [ ] 4.5 A second model reviews the pages (CLAUDE.md, dispatch rule 3).

## 5. Close

- [ ] 5.1 `loop/guardrails/verify.sh` passes.
- [ ] 5.2 A human wires the matrix freshness check into CI (`.github/workflows/` is supervised).
