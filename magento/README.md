# Magento demo (2.4+)

This folder provides a Magento 2.4+ demo overlay for `gplanchat/durable-module`.

## What is included

- `composer.json` preconfigured with local path repositories:
  - `../src/DurableModule`
  - `../src/Bridge/Temporal`
  - `../src/Durable`
- `compose.yaml` for local infrastructure:
  - MySQL, OpenSearch, Redis
  - Temporal + Temporal UI

## Bootstrap a local demo (Mage-OS)

This overlay uses Mage-OS packages from `https://repo.mage-os.org/` (no Adobe Commerce credentials required).
The demo is pinned to `mage-os/product-community-edition:2.2.0` (PHP 8.2 compatible) for compatibility with the Durable package dependency graph.

Host PHP extensions required by Mage-OS metapackage include at least:

- `ext-bcmath`
- `ext-ctype`
- `ext-curl`
- `ext-ftp`
- `ext-pdo_mysql`
- `ext-gd`
- `ext-hash`
- `ext-iconv`
- `ext-intl`
- `ext-mbstring`
- `ext-openssl`
- `ext-simplexml`
- `ext-dom`
- `ext-soap`
- `ext-sodium`
- `ext-xsl`
- `ext-zip`

Quick check:

```bash
php -m | grep -E "pdo_mysql|simplexml|dom|xsl|zip"
```

Automated precheck (recommended):

```bash
cd magento
bash ./check-php-extensions.sh
```

```bash
cd magento
cp .env.example .env
docker compose up -d
composer install
```

If installation still fails, verify network access to `repo.mage-os.org` and run:

```bash
composer clear-cache
composer update
```

If `ext-pdo_mysql` is missing on Debian/Ubuntu:

```bash
sudo apt-get update
sudo apt-get install -y php8.2-mysql
```

Then enable the module in your Magento instance:

```bash
bin/magento module:enable Gplanchat_DurableModule
bin/magento setup:upgrade
bin/magento cache:flush
```

Quick status check:

```bash
bin/magento module:status Gplanchat_DurableModule
```

If `setup:upgrade` fails with `configuration for DB connection is absent`, run Magento setup install first:

```bash
bin/magento setup:install \
  --base-url=http://localhost/ \
  --db-host=127.0.0.1 --db-name=magento --db-user=magento --db-password=magento \
  --admin-firstname=Admin --admin-lastname=User --admin-email=admin@example.com \
  --admin-user=admin --admin-password='Admin123!' \
  --language=en_US --currency=USD --timezone=UTC --use-rewrites=1 \
  --search-engine=opensearch --opensearch-host=127.0.0.1 --opensearch-port=9201 \
  --backend-frontname=admin
```

If your previous attempts partially initialized modules, re-enable core modules before install:

```bash
bin/magento module:enable --all
```

## Durable configuration

Set Temporal DSN in Magento Admin:

- `Stores > Configuration > General > Durable`
  - `Temporal DSN`: `temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0`

If this field is empty, the module uses `DURABLE_DSN` environment variable.

## Dashboard location

- Admin menu: `Stores > Configuration > Durable Dashboard`
- Route: `/admin/durable_dashboard/dashboard/index`

## Reasoning vertical slice (equivalent demo)

The cross-platform slice reuses the same durable phase activities:

- `reasoning.phase.thinking`
- `reasoning.tool.execute`
- `reasoning.tool.compensate`
- `reasoning.phase.review`
- `reasoning.phase.finalize`

Generate an execution from the Symfony sample app, then inspect it in Magento dashboard:

```bash
cd ../symfony
php bin/console durable:sample Samples_Reasoning_OrderAssistant
```

In Magento admin dashboard, validate:

- phase timeline (`thinking`, `tool`, `review`, `finalize`) is visible;
- rollback and compensation are visible when the run fails on tool execution;
- execution can be reopened after service restart without losing history.

## Post-install quick validation

1. Check module state:

```bash
bin/magento module:status Gplanchat_DurableModule
```

2. Open admin login (after `setup:install`):
   - `http://localhost/admin`

3. In back-office:
   - Go to `Stores > Configuration > General > Durable` and set `Temporal DSN`.
   - Open `Stores > Configuration > Durable Dashboard` and verify workflow rows appear.
   - Click a run in the left list to open:
     - execution timeline (execution/activity/signal/query/update lanes),
     - recent Temporal events list (event id, timestamp, type, category).

## Troubleshooting

- 2FA blocks admin login in local demo
  - Disable 2FA module:
    - `bin/magento module:disable Magento_TwoFactorAuth`
    - `bin/magento cache:flush`
  - Re-enable later if needed:
    - `bin/magento module:enable Magento_TwoFactorAuth`
- `Class "Magento\Setup\Mvc\Bootstrap\InitParamListener" not found`
  - Run `composer dump-autoload` in `magento/` (this overlay declares `Magento\\Setup\\` autoload in `composer.json`).
- `You do not have the SUPER privilege ... CREATE TRIGGER` during install
  - This overlay sets MySQL with `--log-bin-trust-function-creators=1` in `compose.yaml`.
  - Recreate DB service: `docker compose up -d --force-recreate magento-db`.
- `Could not validate a connection to the OpenSearch. No alive nodes found`
  - Ensure OpenSearch is up: `docker compose ps opensearch`.
  - This overlay maps OpenSearch to host port `9201` by default to avoid common local conflicts.
- Durable Dashboard returns 404/500 after module updates
  - Refresh the mirrored path package and Magento generated code:
    - `composer update gplanchat/durable-module`
    - `composer reinstall gplanchat/durable-module`
    - `bin/magento setup:upgrade`
    - `bin/magento cache:flush`
  - The dashboard URL is: `http://127.0.0.1:8080/admin/durable_dashboard/dashboard/index/`
- Dashboard details show only `Execution` lane (no activity/events)
  - Ensure there is real Temporal history for the selected run.
  - Quick way to generate one from Symfony samples on Magento Temporal:
    - `cd ../symfony`
    - `php bin/console durable:temporal:native-spike --dsn="temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0"`
  - Then open:
    - `http://127.0.0.1:8080/admin/durable_dashboard/dashboard/index/?run=<run-id>`
  - If still unchanged after source edits, refresh the mirrored module copy:
    - `cd ../magento`
    - `composer reinstall gplanchat/durable-module`
    - `bin/magento cache:flush`

## Notes

- This repository does not ship a full preinstalled Magento distribution in VCS.
- The demo overlay and module are provided to wire Durable dashboard features in a standard Mage-OS / Magento 2.4+ compatible setup.
