# Splitsh CI: verification vs automatic push to satellite repositories

ADR017-splitsh-ci-and-satellite-pushes
===

Status: **Accepted**

## Context

The monorepo publishes several **read-only mirrors** (Packagist, downstream clones) from path prefixes via **splitsh-lite** (`bin/splitsh-publish.sh`). Operators need:

1. **CI verification** that each prefix still produces a valid split commit after changes.
2. **Optional automation** to `git push` those commits to `github.com/gplanchat/durable`, `durable-bundle`, etc., without running splitsh manually on a laptop.
3. **Faster CI** by caching the compiled `splitsh-lite` binary (Go + libgit2 build is not free).

## Decision

1. **Workflow** — `.github/workflows/splitsh.yml` runs on `push` to `main` / `master` and `workflow_dispatch`, with `fetch-depth: 0` and `libgit2` installed for splitsh/lite v2.
2. **Default behaviour** — The job **always** runs `splitsh-lite` per prefix and prints the resulting SHAs (same as local `bin/splitsh-publish.sh`).
3. **Optional push** — If the repository secret **`SPLITSH_PUSH_TOKEN`** is set to a **Personal Access Token** (classic) or fine-grained token with **`contents: write`** on each satellite repository, the same script run **also** executes `git push` for each prefix (`SPLITSH_PUSH_TOKEN` is read by `bin/splitsh-publish.sh`). If the secret is absent or empty, pushes are skipped; no failure.
4. **Target branch** — Configurable via **`SPLITSH_TARGET_BRANCH`** (default `main`). Rare force updates: **`SPLITSH_PUSH_FORCE=1`** (use with care).
5. **Cache** — The compiled `splitsh-lite` binary is stored under **`~/.local/bin/splitsh-lite`** and restored via **`actions/cache`** (restore / conditional build / save) so repeated runs skip the Go build when the cache key hits.

## Consequences

- **Security**: The PAT must not be logged; the script uses HTTPS URLs with `x-access-token` and must never `echo` the token. Repository **Settings → Secrets** only.
- **`GITHUB_TOKEN`** is insufficient: it only applies to the **current** repository, not to `gplanchat/durable` and siblings. A dedicated PAT (or six deploy keys) is required for cross-repo pushes.
- **History rewrites** on satellites (force push) are operational risk; keep `SPLITSH_PUSH_FORCE` off unless you intend to repair split history.

## References

- `bin/splitsh-publish.sh`
- `.github/workflows/splitsh.yml`
