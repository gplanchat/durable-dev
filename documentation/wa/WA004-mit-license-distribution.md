# WA004 — MIT license for the repository and Composer packages

## Status

Accepted

## Context

The Durable monorepo and its **satellite repositories** (see **DUR020**) are intended for **open distribution** (Packagist, GitHub, forks). A single, permissive license reduces friction for adopters and keeps legal expectations aligned across the root project and split packages.

## Agreement

### License choice

**All first-party source code, documentation, and assets** in this repository that are authored as part of the Durable project are distributed under the **MIT License** (SPDX: `MIT`), unless a file explicitly states a different license (e.g. vendored third-party snippets with retained headers).

### Repository

- A plain-text file named **`LICENSE`** at the **monorepo root** contains the full MIT text and is the **canonical** copy for this repository.
- The root **`composer.json`** declares `"license": "MIT"**.

### `LICENSE` at each first-party package root

Each **first-party subtree** that maps to a Composer package or a **satellite repository** (splitsh) **must** include its own plain-text **`LICENSE`** file at **that subtree’s root** (same MIT text as the monorepo root). This keeps licence terms visible when browsing a package folder alone and ensures splits publish a repo with `LICENSE` at the published root.

| Context | `composer.json` path | `LICENSE` path |
|---------|----------------------|----------------|
| Monorepo root (dev tooling) | `composer.json` | `LICENSE` |
| `gplanchat/durable` | `src/Durable/composer.json` | `src/Durable/LICENSE` |
| `gplanchat/durable-bundle` | `src/DurableBundle/composer.json` | `src/DurableBundle/LICENSE` |
| `gplanchat/durable-bridge-temporal` | `src/Bridge/Temporal/composer.json` | `src/Bridge/Temporal/LICENSE` |
| Symfony sample application | `symfony/composer.json` | `symfony/LICENSE` |

### Composer packages (first-party)

Each first-party **`composer.json`** in this repository **must** declare `"license": "MIT"`:

| Package / context | Typical path |
|-------------------|----------------|
| Monorepo root (dev tooling) | `composer.json` |
| `gplanchat/durable` | `src/Durable/composer.json` |
| `gplanchat/durable-bundle` | `src/DurableBundle/composer.json` |
| `gplanchat/durable-bridge-temporal` | `src/Bridge/Temporal/composer.json` |
| Symfony sample application | `symfony/composer.json` |

### Satellite repositories (splitsh)

Repositories produced from this monorepo via **splitsh** (DUR020) **must** include the same **MIT** terms: the split prefix **already** contains a root **`LICENSE`** file (per the table above); the published satellite **must** keep **`"license": "MIT"`** in `composer.json`. Do not rely on Composer metadata alone without a **`LICENSE`** file at the published repository root.

### Third-party dependencies

Dependencies **retain their own licenses**; this WA does not override upstream terms. Adding a dependency that is **incompatible** with distributing our code under MIT requires an **explicit review** (new ADR or WA update).

### User-visible documentation

The root **README** (and package READMEs where relevant) **should** state that the project is under the **MIT License** and point to the relevant **`LICENSE`** file (monorepo root or package directory).

## Consequences

- New packages or directories with a `composer.json` **must** use `"license": "MIT"` and add a root **`LICENSE`** file (MIT text aligned with the monorepo canonical copy) unless a documented exception exists.
- Pull requests that change the license or introduce copyleft/proprietary **constraints** on our code need explicit maintainer agreement and document updates **before** merge.

## References

- [MIT License (OSI)](https://opensource.org/licenses/MIT)
- [DUR020 — Monorepo, splitsh, and satellite repositories](../adr/DUR020-monorepo-splitsh-and-satellite-repositories.md)
- [documentation/INDEX.md](../INDEX.md)
