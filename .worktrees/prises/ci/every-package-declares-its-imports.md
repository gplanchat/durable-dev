# ci/every-package-declares-its-imports

- **Scope**: #346 — `composer validate --strict` on every manifest and
  `composer-require-checker` per published package in CI; the undeclared imports and manifest
  drift the ticket lists, declared or dropped.
- **Entries**: `composer.json` files, `.github/workflows/`, `src/DurableModule` wiring.
- **State**: in review — PR #479, durable-d1.
