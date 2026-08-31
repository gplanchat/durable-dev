# `gplanchat/durable-bundle`

Symfony bundle for **`gplanchat/durable`**: configuration, autoconfiguration of workflows and activities, **Messenger** integration, and optional profiler support.

> **Read-only mirror.** This repository is a subtree-split of
> **[gplanchat/durable-dev](https://github.com/gplanchat/durable-dev)**, published so Composer can
> require this package on its own. Issues and pull requests are disabled here — open them **[on the
> monorepo](https://github.com/gplanchat/durable-dev/issues)**.
>
> **The tests are in the monorepo, not here.** This split carries source only. What covers it is
> `tests/unit/DurableBundle/` and `tests/integration/Durable/Bundle/` in the monorepo, run by its
> `unit` and `integration` suites.
>
> **Documentation**: [durable.rocks](https://durable.rocks).

## Requirements

- PHP **8.2+**
- Symfony **6.4 || 7.4** (`framework-bundle`, `messenger`, etc. — see `composer.json`)

## Documentation

- **User guide**: [`documentation/user/getting-started/`](https://durable.rocks/docs/getting-started/) and [`documentation/user/configuration/`](https://durable.rocks/docs/configuration/)
- **Messenger and workers**: **DUR021** in [`documentation/INDEX.md`](https://github.com/gplanchat/durable-dev/blob/main/documentation/INDEX.md)

## Install

```bash
composer require gplanchat/durable-bundle
```

Register the bundle in your kernel and add `config/packages/durable.yaml` (see the getting-started guide).

## Suggested dev dependency

- `symfony/web-profiler-bundle` — Durable toolbar / profiler panel (see `composer.json` `suggest`)

## License

**MIT** — see [`LICENSE`](LICENSE) in this directory and [WA004](https://github.com/gplanchat/durable-dev/blob/main/documentation/wa/WA004-mit-license-distribution.md).
