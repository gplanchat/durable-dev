# OST002 — PHPUnit 12 upgrade checklist

## Context

The repository uses **PHPUnit 11.5** with **attribute** metadata (`#[Test]`, `#[CoversClass]`, etc.), per ADR003. PHPUnit 12 will remove docblock annotations for tests.

## PHPUnit 11 — hygiene (CI without warnings)

- Replace any PHPUnit metadata in **docblocks** (`@coversNothing`, `@covers`, etc.) with the matching **attributes** (`#[CoversNothing]`, `#[CoversClass]`, …). Otherwise the output shows *PHPUnit test runner deprecations* and *OK, but there were issues!*.

## Before upgrading to PHPUnit 12

1. **Read** the official PHPUnit 12 changelog / upgrade guide (breaking changes, extensions).
2. **Bump** `phpunit/phpunit` in `composer.json` / lock after a stable release.
3. **Run** `composer cs:check` and `composer test` (or `./vendor/bin/phpunit --strict-coverage`); adjust `phpunit.xml` if the XSD schema changes. Check `.github/workflows/ci.yml`.
4. **Verify** third-party extensions or bridges (Symfony, paratest, etc.) for PHP 12 compatibility.
5. **Coverage**: if `beStrictAboutCoverageMetadata` is enabled, ensure each test has coherent `#[CoversClass]` / `#[CoversMethod]` / `#[CoversFunction]` targets for executed code.

## Tracking

- Revise this document on each PHPUnit 12 RC / beta.
- Update ADR003 if new testing rules apply.

## References

- [ADR003 — PHPUnit standards](../adr/ADR003-phpunit-testing-standards.md)
