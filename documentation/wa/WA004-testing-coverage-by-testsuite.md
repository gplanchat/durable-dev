# Couverture de tests par échelon (objectif ≥ 80 %)

WA004-testing-coverage-by-testsuite  
===

Introduction
---

Cet accord complète le plan d’audit Temporal vs DBAL (§6bis) : viser **au moins 80 %** de couverture de **lignes** (PCOV) **par testsuite** PHPUnit : `unit`, `functional`, `integration` (répertoires sous `tests/`).

Mesure
---

- Utiliser **PCOV** (ou Xdebug en mode coverage) avec `--coverage-text` ou rapport Clover/XML.
- Produire un rapport **par** `--testsuite=unit|functional|integration` avec les mêmes `--coverage-filter` sur `src/` (voir `composer.json` scripts `test:coverage:*`).
- Le pourcentage global `composer test:coverage` ne suffit pas à valider l’objectif par échelon.

CI
---

- À terme : job dédié ou étapes qui échouent si un seuil n’est pas atteint (parser le rapport ou utiliser un outil tiers).
- Tant que la base n’atteint pas 80 % partout, les scripts documentés ici servent de **cible** et de **suivi** manuel ou rapport d’artefact.

Références
---

- [ADR003](../adr/ADR003-phpunit-testing-standards.md) — standards PHPUnit.
- Plan d’audit (§6bis).
