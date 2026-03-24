# OST002 — Checklist montée de version PHPUnit 12

## Contexte

Le dépôt est sur **PHPUnit 11.5** avec métadonnées en **attributs** (`#[Test]`, `#[CoversClass]`, etc.), conformément à ADR003. PHPUnit 12 supprimera les annotations docblock pour les tests.

## Avant de passer à PHPUnit 12

1. **Lire le changelog / upgrade guide** officiel PHPUnit 12 (breaking changes, extensions).
2. **Mettre à jour** `phpunit/phpunit` en `composer.json` / lock après sortie stable.
3. **Exécuter** `composer cs:check` et `composer test` (ou `./vendor/bin/phpunit --strict-coverage`) ; ajuster la config `phpunit.xml` si le schéma XSD change. Vérifier le workflow `.github/workflows/ci.yml`.
4. **Vérifier** les extensions ou bridges tiers (Symfony, paratest, etc.) pour compatibilité 12.
5. **Couverture** : si `beStrictAboutCoverageMetadata` est activé, valider que chaque test a des cibles `#[CoversClass]` / `#[CoversMethod]` / `#[CoversFunction]` cohérentes avec le code exécuté.

## Suivi

- Réviser ce document à chaque RC / beta de PHPUnit 12.
- Mettre à jour ADR003 si de nouvelles règles de tests s’imposent.

## Références

- [ADR003 — Standards PHPUnit](../adr/ADR003-phpunit-testing-standards.md)
