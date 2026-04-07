# `gplanchat/durable-bundle`

Symfony bundle for **`gplanchat/durable`**: configuration, autoconfiguration of workflows and activities, **Messenger** integration, and optional profiler support.

## Requirements

- PHP **8.2+**
- Symfony **6.4 || 7.4** (`framework-bundle`, `messenger`, etc. — see `composer.json`)

## Documentation

- **User guide**: [`documentation/user/getting-started/`](../../documentation/user/getting-started/) and [`documentation/user/configuration/`](../../documentation/user/configuration/)
- **Messenger and workers**: **DUR021** in [`documentation/INDEX.md`](../../documentation/INDEX.md)

## Install

```bash
composer require gplanchat/durable-bundle
```

Register the bundle in your kernel and add `config/packages/durable.yaml` (see the getting-started guide).

## Suggested dev dependency

- `symfony/web-profiler-bundle` — Durable toolbar / profiler panel (see `composer.json` `suggest`)
