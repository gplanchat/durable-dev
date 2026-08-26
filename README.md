# Durable (PHP)

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)

**Durable** is a PHP library for **durable execution**: long-running workflows coordinated with **Temporal**, with a **cursor-based event journal**, **activities** for side effects, and **replay** so workflow code stays deterministic.

This monorepo contains:

| Package | Path | Role |
|--------|------|------|
| `gplanchat/durable` | [`src/Durable/`](src/Durable/) | Core library (workflows, activities, event store, in-memory and integration surfaces) |
| `gplanchat/durable-bundle` | [`src/DurableBundle/`](src/DurableBundle/) | Symfony bundle (Messenger, configuration, profiler) |
| `gplanchat/durable-bridge-temporal` | [`src/Bridge/Temporal/`](src/Bridge/Temporal/) | Temporal gRPC bridge (no official Temporal PHP SDK; see **DUR006**) |
| `gplanchat/durable-bridge-dbal` | [`src/Bridge/Dbal/`](src/Bridge/Dbal/) | Doctrine DBAL journal + stores: durable execution on one SQL database, no cluster (**DUR030**) |
| `gplanchat/durable-plugin` | [`src/DurablePlugin/`](src/DurablePlugin/) | Sylius 2 admin plugin: workflow dashboard, backend-neutral (**DUR037**) |
| Sample app | [`symfony/`](symfony/) | Example Symfony application using the bundle + Temporal |
| Sylius shop | [`sylius/`](sylius/) | Sylius 2.2 Standard — where the admin dashboard is rendered for real |

### Booting the Sylius shop

Sylius Standard 2.2 requires **PHP 8.3** and an extension set no PHP on a typical dev box carries in
one place, so the shop runs on the image the skeleton ships with — never on the host PHP:

```bash
cd sylius
cp compose.override.dist.yml compose.override.yml   # monte l'app, et `../src` dont elle dépend
docker compose up -d                                # php 8.3, MySQL 8.4, nginx, mailhog
docker compose run --rm php composer install
docker compose run --rm php bin/console debug:router
```

`composer.lock` is tracked here, unlike in the upstream skeleton: this shop is a test bench in CI,
and an unpinned resolve would let a Sylius release break the build with no commit of ours behind it.


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
- **Monorepo → satellite repositories (splitsh)**: [`bin/splitsh-publish.sh`](bin/splitsh-publish.sh), [DUR020](documentation/adr/DUR020-monorepo-splitsh-and-satellite-repositories.md), [`.github/workflows/splitsh.yml`](.github/workflows/splitsh.yml) — pushes to `main`/`master` and tags `v*` propagate to satellites when `SPLITSH_PUSH_TOKEN` is configured.

Architecture decisions for this component use the **`DUR`** prefix under `documentation/adr/` (see **DUR000**).

## License

This project is released under the [MIT License](https://opensource.org/licenses/MIT) (SPDX identifier: `MIT`). The full text is in [`LICENSE`](LICENSE); distribution policy is described in [WA004](documentation/wa/WA004-mit-license-distribution.md).
