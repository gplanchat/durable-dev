# Durable + Symfony sample application

Demonstrates `gplanchat/durable` with **Messenger**, **Doctrine DBAL** (SQLite by default), and class-based workflows (`App\Durable\Workflow\`).

## Requirements

- PHP 8.2+
- Composer — dependencies live under **`symfony/vendor/`** (Composer default). Run **`composer install`** from this `symfony/` directory.
- Packages **`gplanchat/durable`** and **`gplanchat/durable-bundle`** are resolved via **path** from **`../src/Durable`** and **`../src/DurableBundle`** at the monorepo root. For an app outside the monorepo: `composer require gplanchat/durable-bundle` from Packagist.

After changing the `vendor` layout (e.g. moving away from an external `durable-symfony-vendor`), clear cache: **`rm -rf var/cache/*`** then **`php bin/console cache:clear`**.

## Installation

```bash
cd symfony
composer install
```

## Database (log / metadata / parent–child link tables)

In **dev** (`config/packages/durable.yaml`), the Durable log and async parent–child link use **DBAL** (same connection as Doctrine Messenger).

**Idempotent** initialization:

```bash
php bin/console durable:schema:init
```

Then start workers and samples (see the component root README).

## PHPUnit (this app)

```bash
cd symfony
composer test
# or: php bin/phpunit
```

Tests use `sqlite:///:memory:` (see `phpunit.xml.dist`). They cover **`durable:schema:init`** (idempotence) and **`durable:sample`** (GreetingWorkflow, ParallelGreetingWorkflow, ParentCallsEchoChildWorkflow, ParallelChildEchoWorkflow, TimerThenTickWorkflow, SideEffectRandomIdWorkflow).
