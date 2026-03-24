# PRD004 — Intégration continue (GitHub Actions)

## Objectif

Garantir à chaque push / PR : style de code conforme, tests PHPUnit avec métadonnées de couverture strictes, et rapport de couverture sur `src/`.

## Comportement livré

| Élément | Rôle |
|--------|------|
| `.github/workflows/ci.yml` | Pipeline GitHub Actions |
| Job `qa` | PHP 8.2 & 8.3 — `composer install`, `composer cs:check`, `composer test` |
| Job `symfony-sample` | PHP 8.2 & 8.3 — `composer install` + `composer test` dans **`symfony/`** (app exemple, `vendor/` local) |
| Job `coverage` | PHP 8.2 + PCOV — `composer test:coverage` |
| `composer.json` | Contrainte **`"php": ">=8.2"`** ; scripts `test`, `test:coverage` ; suggestion **`ext-pcov`** |

## Prérequis contributeur

- Pour `composer test:coverage` en local : installer **PCOV** (ou Xdebug), sinon utiliser uniquement `composer test`.

## Évolutions possibles

- Seuil minimal de couverture (lignes / branches) une fois la politique définie.
- Upload vers Codecov ou équivalent.

## Références

- [ADR003 — Standards PHPUnit](../adr/ADR003-phpunit-testing-standards.md)
- [ADR002 — Standards de code](../adr/ADR002-coding-standards.md)
