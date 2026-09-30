# DurableFilament (Filament)

`gplanchat/durable-filament` is a Filament panel plugin: a read-only dashboard of Durable workflow
runs, on Filament 3 or 4.

> **Read-only mirror.** This repository is a subtree-split of
> **[gplanchat/durable-dev](https://github.com/gplanchat/durable-dev)**, published so Composer can
> require this package on its own. Issues and pull requests are disabled here — open them **[on the
> monorepo](https://github.com/gplanchat/durable-dev/issues)**.
>
> **Documentation**: [durable.rocks](https://durable.rocks).

## Install

```bash
composer require gplanchat/durable-filament
```

```php
use Gplanchat\Durable\Filament\DurableFilamentPlugin;

$panel->plugin(DurableFilamentPlugin::make());
```

## Features

- Workflow runs list with cursor paging, filtered by workflow name and execution id prefix where
  the backend can apply them.
- A page per run: status, what it waits on, its Nexus operations, and its history, one block per
  action.
- Reads whichever catalog `gplanchat/durable-laravel` binds: in-memory, Illuminate or Temporal.
- English and French.

It observes and never runs a workflow.

## Tests

The tests are not in the package archive: they run from the monorepo. They boot a Laravel
application with one panel over the `vendor/` of a project that installs this package through path
repositories, with Filament 3 or 4, and maps `Gplanchat\Durable\Filament\Tests\` to
`src/DurableFilament/tests/` in its `autoload-dev`.
