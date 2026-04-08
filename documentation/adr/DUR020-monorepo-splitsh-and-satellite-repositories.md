# DUR020 — Monorepo, splitsh, and satellite repositories

## Status

Accepted

## Context

Durable component code and related packages may live in a **monorepo**, with **read-only mirrors** (Packagist, downstream clones) published from **path prefixes** via **splitsh-lite** or equivalent. Operators need **CI verification** that splits remain valid and, optionally, **automatic pushes** to satellite repositories.

## Decision

### Goals

1. **CI**: on each relevant change, verify each configured prefix still produces a valid split commit (coherent Git history).
2. **Optional automation**: allow `git push` of split commits to remote repos (e.g. separate Composer packages) without relying on manual laptop runs every time.
3. **Performance**: **cache** the splitsh binary (Go build / **libgit2** dependency) to speed up pipelines.

### Behaviour

- The CI workflow runs splitsh **per prefix** and exposes resulting **SHAs** (reproducible with an in-repo script).
- **Push** to satellites is **conditional**: only if an auth secret (PAT or deploy keys) is configured; otherwise the job **does not fail** — verification only runs.
- Default **`GITHUB_TOKEN`** in Actions is usually **not** enough to push to **other** repos: use a dedicated **PAT** with `contents: write` on each target, or per-repo equivalent.

### Security

- Never **log** secrets; HTTPS URLs with tokens must not appear in CI logs.
- **Force push** on satellites is **high risk**; reserve for planned history repairs.

### Target branch

- Configurable (e.g. default `main`); any **force** option explicitly named and off by default.

### Version tags on satellites

Pushing a version tag on the monorepo (e.g. `v0.1.0`) does **not** automatically create that tag on satellite mirrors unless automation runs **splitsh at the tagged commit** and **`git push <split-sha>:refs/tags/<tag>`** to each satellite.

- **CI** (`.github/workflows/splitsh.yml`): on **`push` of tags matching `v*`**, the workflow runs `bin/splitsh-publish.sh tag <tag>` so each configured prefix gets the same tag name pointing at the **split commit** that corresponds to the monorepo tag.
- **Branch pushes** to `main` / `master` run `bin/splitsh-publish.sh` without arguments (split current `HEAD`, push to `refs/heads/<target>`).
- **Manual backfill**: with `splitsh-lite` locally and `SPLITSH_PUSH_TOKEN` set, from a **clean** tree: `./bin/splitsh-publish.sh tag v0.1.0`.

Prefixes and GitHub repository names live in **`bin/splitsh-publish.sh`** (`SPLITSH_GITHUB_ORG` defaults to `gplanchat`).

## Consequences

- The repository must document **prefixes**, publish script, and satellite repo names in the README or contributor docs.
- Maintainers configure **secrets** at the CI repository level.
- Each split **prefix** must ship a root **`LICENSE`** file (MIT) per **[WA004](../wa/WA004-mit-license-distribution.md)** so satellite mirrors match Packagist and GitHub expectations.

## Relationship to workflow and activity authoring (DUR022, DUR023)

- Satellite splits (e.g. publishing `src/Durable`) do **not** change the **authoring** model in **DUR022** / **DUR023**; those ADRs remain the normative contract for consumers of each package.
