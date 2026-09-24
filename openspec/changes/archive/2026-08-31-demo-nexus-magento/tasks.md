# Tasks

## 0. Probe before building

- [x] 0.1 **The Magento bench's PHP has `ext-grpc`, and it is not the same one as the other two
      sample apps'.** The bench has no PHP container: its `compose.yaml` only mounts MySQL,
      OpenSearch, Redis and a Temporal cluster, and `bin/magento` runs on the host's PHP.
      Re-measured on the reference workstation:

      | PHP | grpc | curl | intl | pdo_mysql | soap |
      |---|---|---|---|---|---|
      | **8.2** | ✅ | ✅ | ✅ | ✅ | ✅ |
      | 8.3 | ✅ | ❌ | ✅ | ❌ | ❌ |
      | 8.4 | ❌ | ✅ | ❌ | ❌ | ❌ |

      The §0.1 table of the two-application change concluded there was a narrow path because the
      Sylius sample app **requires** PHP ≥ 8.3; the Magento bench is pinned to Mage-OS 2.2.0, which
      accepts 8.2 — the only version on the workstation that has everything. The demonstration
      therefore runs on **two PHP binaries**, and `demo/lancer.sh` carries `PHP` and `PHP_MAGENTO`
      separately. No single version runs all three sample apps.
- [x] 0.2 **Calling needs no host, and this is measured, not read.** Read first:
      `WorkflowEnvironment::nexusStub()` resolves the contract by reflection and touches no
      container, and `WorkflowTaskRunner` is the same class for all three hosts
      (`TemporalJournalTransport`, `RuntimeFactory::journalWorker()`, `DurableServiceProvider`).
      Then measured, in §5: not a single line was added to the core, the Temporal bridge or
      `gplanchat/durable-magento` for the sample app to call.

      ⚠ **The failure to know about, because its message blames the wrong side.** The first two
      attempts failed with
      `No Nexus handler is registered for operation "verifier" of service "facturation"` —
      `NOT_IMPLEMENTED`, non-retryable, returned by the business app's Nexus worker. The cause of
      the first one was **on the caller's side**: `symfony/vendor` did not have
      `gplanchat/durable-demo-contracts`, its `composer install` predating the two-application
      change. The message names the handler, the endpoint and the operation — everything except
      the missing installation.
      The second failure happened right after a worker restart, on a call identical to the ones
      that succeeded afterwards (MAG-3, MAG-4, including after a restart followed by a
      five-second wait). **The cause is not established**: the container cache had been cleared by
      the `composer install`, so the "cold container" explanation does not hold. What is
      reproducible is the diagnosis, not the failure: that message means *handler registry
      without the entry*, and both sides have to be checked.
- [x] 0.3 **The bench's cluster is not suitable**, and the bench did not have to change for it.
      `temporalio/auto-setup:1.25.2` answers `Nexus APIs are disabled`. The demonstration's DSN
      comes in through `MAGENTO_DC_DURABLE__TEMPORAL__DSN`, Magento's convention for overriding
      `app/etc/env.php` from the environment (`MAGENTO_DC_` + the path, `__` for `/`) —
      checked in `DeploymentConfig::getAllEnvOverrides()`, which applies it **regardless of**
      whether the key is present in the file. `app/etc/env.php` is not modified, and it is not
      under version control.

## 1. The shared contract, third consumer

- [x] 1.1 **An `autoload` entry rather than a path repository, and that is a decision, not a shortcut.**
      The other two sample apps declare `gplanchat/durable-demo-contracts` as a path repository. The
      Magento bench adds a `psr-4` line pointing to `../src/DurableDemoContracts/`, for three
      measured reasons:

      1. its three path repositories are set to `"symlink": false` — Composer **copies**, so any
         change to the contract would require a `composer update` of the bench to be seen, which is
         exactly the trap a demonstration must not set;
      2. CI does not install this bench: `magento-matrix` sets up a throwaway project, and the lock
         here guards nothing;
      3. `composer dump-autoload` is enough, in one second and without network, where a path
         repository would require a full resolution of the Mage-OS lock.

      The longest prefix wins in PSR-4: `Gplanchat\Durable\Demo\Contracts\` is served by
      this entry and not by the core package's `Gplanchat\Durable\`. Checked —
      `interface_exists(StockContract::class)` returns `true` from the bench's autoload.
- [x] 1.2 ⚠ **The naming guard does not exist on this side, and does not need to.** The rule "every
      workflow parameter without a default value must be a contract parameter" lives in
      `NexusHandlerPass`, hence in the Symfony container. It guards the **server**. Magento serves
      nothing: what it must get right are the names passed to the stub's methods, and the typed
      signature of the contract already checks them at write time. Stated in the workflow's header.

## 2. The calling workflow

- [x] 2.1 `CommandeNexusWorkflow` in `Gplanchat_DurableProbe` — the bench module, not the published
      package. Two stubs, two endpoints, three operations.

      **The order of the three calls changed after measurement**, and it is the only design flaw
      the demonstration found. Written first as `reserver` then `verifier`, it left a dead-end
      case: `MAG-6`, in USD, held `MUG_BLUE` at the shop **then** had its invoice refused — and the
      `stock` contract has no operation that gives back what was taken. Verifying first means there
      is nothing to give back: an invoice refusal does not touch the stock, and a stock refusal does
      not touch the money, `encaisser` coming after both.
- [x] 2.2 `bin/magento durable:demo:nexus` starts the execution **on the cluster** through
      `RuntimeFactory::workflowClient()`: `MagentoRuntime::run()` would have executed it in the
      command's process, which is the opposite of what the demonstration shows.
      Its last line says what happened, not what usually happens — a refusal comes back
      in 0.3 s, and announcing "including the payment capture" would pass a refusal off as an
      abnormally fast capture.
- [x] 2.3 The workflow and the command are declared in the bench's `di.xml`: Magento's container
      does not have Symfony's tags, nothing picks up `#[AsWorkflow]` on its own.

## 3. The cluster

- [x] 3.1 `bin/demo-nexus` creates the `demo-magento` namespace and **no additional endpoint**. Three
      namespaces, two endpoints: an endpoint says where a service is served, and Magento serves nothing.
      Measured at creation: `+ namespace demo-magento`, and both endpoints unchanged.

## 4. Running the demonstration

- [x] 4.1 `demo/lancer.sh` starts the bench's journal worker. **Six processes, not seven**:
      `CommandeNexusWorkflow` has no activity — everything it does is served elsewhere —, so no
      activity worker, exactly like the shop and for the same reason.
      The `demarrer` function was split into `lancer` (any command) and `demarrer`
      (a `messenger:consume` in an `APP_ENV`): the three sample apps do not run their workers
      the same way, and the difference fits in one argument.
- [x] 4.2 The script prints the third call with the right values, `PHP_MAGENTO` included.

## 5. Put to the test

- [x] 5.1 **Three PHP processes on top of six workers, three namespaces, and the four cases.**
      Bench: `temporal server start-dev --port 7239`, PostgreSQL for the shop, MySQL for
      Magento, `MUG_BLUE` at 5 in stock and `MUG_RED` at 1.

      | call | response | duration | effect |
      |---|---|---|---|
      | `MAG-20 1200 MUG_BLUE=2` | `verifiee.acceptee: true`, `reserve: true`, `recu: RECU-EUR-MAG-20` | 13.9 s | `on_hold` 0 → 2 |
      | `MAG-11 4200 MUG_BLUE=1 --devise=USD` | `acceptee: false`, `motif: devise USD non prise en charge`, `reservation: null` | 0.3 s | nothing — the stock is not even asked |
      | `MAG-12 1200 MUG_RED=3` | `acceptee: true`, `reserve: false`, `manquants: {MUG_RED: 2}` | 0.4 s | nothing, and nothing captured |
      | `MAG-21 1200 MUG_BLUE=1`, opposite worker **shut down 49 s** | result identical to nominal | 49.6 s | `on_hold` +1 |

      The last row is the proof that the two-application demonstration had obtained by
      accident, redone on purpose from Magento: with `metier-workflows` shut down, the operation
      stayed in `NexusOperationStarted` for forty seconds with nothing moving, and everything
      completed normally when the worker came back. Magento held no connection, no process, no transaction.

      ⚠ **A false lead, ruled out by reading the raw payload.** The Temporal CLI displays
      `result.manquants: <nil>` and `"manquants":null` in its flattened view, where the command prints
      `[]`. The stored payload says `{"reservation":{"reserve":true,"manquants":[]}}`: it is indeed the
      `[]` that §2.6 of the previous change already documents — an empty associative array encoded as a list —,
      and not a third shape. Nothing to fix, but the CLI view lies on this point.
- [x] 5.2 **The Nexus events are in Magento's journal**, which lifts the reservation of
      `magento-module` §3bis.9. `durable-MAG-20`, 24 events:

      | # | event | what it carries |
      |---|---|---|
      | 5 → 6 | `NexusOperationScheduled` → `Completed` | `facturation` / `verifier`, settled on the task |
      | 10 → 11 | `NexusOperationScheduled` → `Completed` | `stock` / `reserver`, settled on the task |
      | 15 → 16 → 20 | `Scheduled` → `Started` → `Completed` | `demo-metier-facturation`, `facturation` / `encaisser`, **13 s between 16 and 20** |

      Event 15 names `endpoint`, `service`, `operation` and the payload; event 16 carries an
      `operationToken` and **nothing else** — that is the "in flight" state, the one a dashboard
      must be able to tell apart from a failure. Showing it belongs to `change/dashboard-presentation`;
      producing it belonged here.

## 6. Saying it

- [x] 6.1 `demo/README.md`: three sample apps, six processes, the third namespace, and the
      prerequisites of the Magento bench.
- [x] 6.2 `documentation/user/nexus/_index.{md,fr.md}`: the section becomes "Three applications, for
      real", followed by "Calling asks nothing of your host" — the caller/server asymmetry, the
      three namespaces for two endpoints, and the call order as compensation.
      Checked on a `--minify` build served over HTTP, both languages symmetrical: **8 `h2`,
      6 `h3`, 2 tables, 13 code blocks** on each side, and the sample-apps table does render
      its four columns.
- [x] 6.3 The `EveryCaseWorkflow` reservation and the bench's `README.md` now point to the
      workflow that exists, instead of announcing a change to come.
