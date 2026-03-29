# PRD005 — “Empty Symfony project” recipe (~3 minutes)

## Objective

Let an integrator start from a **standard Symfony project** (or nearly empty), add **`gplanchat/durable`**, and get a **first runnable workflow** with minimal files and commands, targeting **about three minutes** once PHP and Composer are available.

## Delivered behavior (documentation + reference app)

| Item | Role |
|--------|------|
| [README.md](../../README.md) — *Quick start* | Step-by-step on the monorepo sample app `symfony/` (clone → `composer install` → `durable:schema:init` → `durable:sample`). |
| [README.md](../../README.md) — *Installing in a Symfony project* | `composer require`, bundle registration, `durable.yaml`-style config, `durable.workflow` tag, schema, workers. |
| [README.md](../../README.md) — *Activities* + *Symfony bundle* | Contract interface + `#[ActivityMethod]`, handler with **`#[AsDurableActivity(contract: …)]`**, `activity_contracts.contracts` list. |
| **`symfony/`** directory | Reference app aligned with the docs (config, handlers, workflows, tests). |
| **`src/Durable*`** subtrees | Chaque paquet publiable (`durable`, `durable-bundle`, PHPStan, Psalm) vit sous `src/` avec son `composer.json` ; publication via **splitsh-lite** (`bin/splitsh-publish.sh`). |

## Target project prerequisites

- PHP **8.2+**, Symfony **7.4+** (bundle).
- Messenger transports configured if `activity_transport.type: messenger` and distributed mode.

## Possible extensions

- Official `symfony new` / Flex recipe once the package is published stably.
- Video or idempotent shell script reproducing quick-start steps.

## References

- [ADR005 — Messenger integration](../adr/ADR005-messenger-integration.md)
- [ADR006 — Activity patterns](../adr/ADR006-activity-patterns.md)
- [PRD001 — Current component state](PRD001-current-component-state.md)
