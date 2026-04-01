# PRD004 — Continuous integration (GitHub Actions)

## Objective

On every push / PR: enforce code style, run PHPUnit with strict coverage metadata, and report coverage on `src/`.

## Delivered behavior

| Item | Role |
|--------|------|
| `.github/workflows/ci.yml` | GitHub Actions pipeline |
| `qa` job | Matrix PHP **8.2–8.5** — `composer install`, `composer cs:check`, `composer test` ; extensions incl. **`grpc`** (tests Bridge/Temporal) |
| `static-analysis` job | PHP 8.2 — `composer phpstan`, `composer psalm` |
| `symfony-sample` job | Matrix PHP 8.2–8.5 — `composer install` + `composer test` in **`symfony/`** |
| `temporal-bridge` job | PHP 8.2 + **grpc** — PHPUnit ciblé (journal / transport factory) |
| `coverage` job | PHP 8.2 + PCOV + **grpc** — `composer test:coverage` (rapport global `src/`) |
| `docker-compose-stack` job | Valide `compose.yaml`, démarre Temporal + UI, smoke HTTP |
| `composer.json` | `test`, `test:coverage`, `test:coverage:unit`, `test:coverage:functional`, `test:coverage:integration` ; **`ext-pcov`** suggested |

## Contributor prerequisites

- For local `composer test:coverage` (ou scripts par testsuite) : installer **PCOV** (ou Xdebug) ; sinon `composer test` seul.
- Tests Bridge/Temporal : **ext-grpc** (voir job `qa`).

## Objectif couverture (cible produit)

- **≥ 80 %** de couverture de lignes **par** testsuite PHPUnit (`unit`, `functional`, `integration`) — mesure via rapports séparés ; voir [WA004 — Couverture par testsuite](../wa/WA004-testing-coverage-by-testsuite.md). La CI publie encore un rapport **global** ; le seuil par échelon peut être ajouté quand la base atteint les objectifs.

## Possible extensions

- Seuil minimum par testsuite ou fail du build sous un % (parser Clover / `coverage-text`).
- Upload vers Codecov ou équivalent.

## References

- [ADR003 — PHPUnit standards](../adr/ADR003-phpunit-testing-standards.md)
- [ADR002 — Coding standards](../adr/ADR002-coding-standards.md)
- [WA004 — Couverture par testsuite](../wa/WA004-testing-coverage-by-testsuite.md)
