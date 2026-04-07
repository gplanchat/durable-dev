# Durable (PHP)

**Durable** is a PHP library for **durable execution**: long-running workflows coordinated with **Temporal**, with a **cursor-based event journal**, **activities** for side effects, and **replay** so workflow code stays deterministic.

This monorepo contains:

| Package | Path | Role |
|--------|------|------|
| `gplanchat/durable` | [`src/Durable/`](src/Durable/) | Core library (workflows, activities, event store, in-memory and integration surfaces) |
| `gplanchat/durable-bundle` | [`src/DurableBundle/`](src/DurableBundle/) | Symfony bundle (Messenger, configuration, profiler) |
| `gplanchat/durable-bridge-temporal` | [`src/Bridge/Temporal/`](src/Bridge/Temporal/) | Temporal gRPC bridge (no official Temporal PHP SDK; see **DUR006**) |
| Sample app | [`symfony/`](symfony/) | Example Symfony application using the bundle + Temporal |

Constraints aligned with project rules: **no official Temporal PHP SDK**, **no RoadRunner** as the Durable runtime (**DUR006**).

## Requirements

- PHP **8.2+**
- For Temporal gRPC: **ext-grpc** (see bridge and sample READMEs)

## Quick start (monorepo)

From the repository root:

```bash
composer install
composer test
```

For the Symfony sample (workers, Docker, PHPUnit):

```bash
cd symfony
composer install
composer test
```

See [`symfony/README.md`](symfony/README.md) for Messenger consumers, `DURABLE_DSN`, and optional Temporal integration tests.

## Documentation

- **Contributor index** (ADRs, working agreements): [`documentation/INDEX.md`](documentation/INDEX.md)
- **Document lifecycle**: [`documentation/LIFECYCLE.md`](documentation/LIFECYCLE.md)
- **User guide (Markdown source)** for Hugo: [`documentation/user/`](documentation/user/) — build instructions in [`documentation/HUGO.md`](documentation/HUGO.md)
- **Per-package READMEs**: [`src/Durable/README.md`](src/Durable/README.md), [`src/DurableBundle/README.md`](src/DurableBundle/README.md), [`src/Bridge/Temporal/README.md`](src/Bridge/Temporal/README.md)

Architecture decisions for this component use the **`DUR`** prefix under `documentation/adr/` (see **DUR000**).

## License

Proprietary (see `composer.json`).
