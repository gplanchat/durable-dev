# The Sylius bench

A Sylius 2.2 Standard application that hosts `gplanchat/durable-plugin`. It is a test bench, not a
shop to deploy.

It serves two things:

- **The Durable dashboard, rendered in a real Sylius admin**, at `/admin/durable/dashboard`. The
  CI job `sylius-shop` boots this kernel against MySQL and requests the page over HTTP.
- **The shop side of the Nexus demonstration.** It serves `stock` (`reserve`, answered by
  `src/Durable/Nexus/StockHandler.php`) and calls `billing` from
  `src/Durable/Workflow/OrderWorkflow.php`, started by `bin/console durable:demo:bill`.

| | |
|---|---|
| PHP | 8.3, Sylius 2.2's floor |
| database | MySQL in CI and in `.env`; the demonstration uses PostgreSQL |
| Durable backend | DBAL, on the default Doctrine connection |

## Profiles

`config/packages/durable.yaml` defines one default and two demonstration profiles.

| `APP_ENV` | journal | Temporal | role |
|---|---|---|---|
| `dev`, `prod`, `test` | DBAL | none | the dashboard reads the SQL journal |
| `demo` | DBAL | DSN from `DURABLE_TEMPORAL_DSN`, `journal: false` | serves `stock`; the worker consumes `durable_nexus` |
| `demo_caller` | the cluster (`journal: true`) | DSN from `DURABLE_TEMPORAL_DSN` | advances `OrderWorkflow`; the worker consumes `durable_workflows` |

Serving and calling need two profiles: a workflow can schedule a Nexus operation only when its
journal is the cluster, and the serving profile keeps the DBAL journal the dashboard reads. The
`stock` handler is tagged in `config/services.yaml` under `when@demo` only, so the environments
without a cluster declare no handler.

Durable messages ride the Doctrine transport; the routing is in `config/packages/messenger.yaml`.

The demonstration's prerequisites (Temporal with Nexus enabled, the database, the stock rows) are
in [`demo/README.md`](../demo/README.md). `demo/run.sh` starts the workers.

## Tests

`tests/Unit` covers the shop's own layers (`src/Domain`, `src/Application`). It needs no kernel and
no database:

```bash
cd sylius
composer install
vendor/bin/phpunit tests/Unit
```

`tests/Functional` boots the Sylius kernel and needs MySQL. The default `DATABASE_URL` in `.env` is
`mysql://root@127.0.0.1/sylius_%kernel.environment%`, so the CI setup is enough: MySQL 8.4 on
`127.0.0.1:3306` with an empty root password.

```bash
docker run -d --name sylius-bench-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=1 -p 3306:3306 mysql:8.4

cd sylius
APP_ENV=test bin/console doctrine:database:create --if-not-exists
APP_ENV=test bin/console doctrine:schema:create
vendor/bin/phpunit tests/Functional
```

To run the application in the containers of `compose.yml`, see "Booting the Sylius shop" in the
[root README](../README.md).
