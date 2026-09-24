# ci/skip-ci-on-prise-pushes

- **Scope**: a push to `main` that touches only the prise registry no longer starts CI or Splitsh. `paths-ignore` on `push` only: pull requests keep producing `ci-ok`.
- **Entries**: `.github/workflows/ci.yml`, `.github/workflows/splitsh.yml` (asked for by the user on 2026-09-24).
- **State**: in review — PR #474 (durable-48).
