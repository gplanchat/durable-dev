# PRD004 — Continuous integration (GitHub Actions)

## Objective

On every push / PR: enforce code style, run PHPUnit with strict coverage metadata, and report coverage on `src/`.

## Delivered behavior

| Item | Role |
|--------|------|
| `.github/workflows/ci.yml` | GitHub Actions pipeline |
| `qa` job | PHP 8.2 & 8.3 — `composer install`, `composer cs:check`, `composer test` |
| `symfony-sample` job | PHP 8.2 & 8.3 — `composer install` + `composer test` in **`symfony/`** (sample app, local `vendor/`) |
| `coverage` job | PHP 8.2 + PCOV — `composer test:coverage` |
| `composer.json` | **`"php": ">=8.2"`** constraint; `test`, `test:coverage` scripts; **`ext-pcov`** suggested |

## Contributor prerequisites

- For local `composer test:coverage`: install **PCOV** (or Xdebug); otherwise use `composer test` only.

## Possible extensions

- Minimum coverage threshold (lines / branches) once policy is defined.
- Upload to Codecov or equivalent.

## References

- [ADR003 — PHPUnit standards](../adr/ADR003-phpunit-testing-standards.md)
- [ADR002 — Coding standards](../adr/ADR002-coding-standards.md)
