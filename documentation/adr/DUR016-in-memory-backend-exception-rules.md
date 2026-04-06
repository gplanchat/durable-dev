# DUR016 — Backend In-Memory : règles et exceptions de stockage

## Statut

Accepté

## Contexte

Le backend **In-Memory** (DUR005) doit offrir une implémentation **fidèle** aux ports pour les tests, tout en restant **simple**. Une **implémentation de référence** peut s’appuyer sur des structures riches (index, invalidation par tags, collections) pour coller au comportement des systèmes persistants. Dans certains cas, un **stockage minimal** (tableaux, structures associatives) suffit et réduit la complexité.

## Décision

### Règle par défaut

- L’implémentation In-Memory **de référence** du composant utilise un mécanisme de stockage **cohérent** avec les besoins des tests (recherche, invalidation, parcours) — détail laissé à l’implémentation, documenté dans le code.

### Exceptions autorisées : stockage simplifié

Un module In-Memory **peut** utiliser un stockage **minimaliste** (ex. `array` en mémoire) **si et seulement si** une majorité des critères suivants s’applique :

1. **Rôle limité** : données surtout **lecture**, peu d’écritures ou écritures au démarrage du test uniquement.
2. **Données statiques ou de configuration** : jeu fixe ou chargé une fois, pas une simulation complète d’une base relationnelle.
3. **Pas besoin** des capacités avancées (invalidation fine, requêtes complexes) pour le scénario couvert.
4. **Clarté** : le code reste plus lisible qu’avec la couche générique pour ce cas précis.

### Quand revenir à l’implémentation riche

- Entités **souvent écrites**, **partagées** entre plusieurs tests ou scénarios nécessitant **nettoyage** sélectif.
- Besoin de **cohérence** avec des comportements proches du **cache** ou du **stockage** réel simulé.

### Documentation

- Toute classe In-Memory « exception » **doit** indiquer en docblock ou documentation courte **pourquoi** le stockage simplifié est justifié (référence implicite à cette ADR par nom « DUR016 »).

### Migration

- Si un stockage minimal devient insuffisant (écritures fréquentes, besoin d’invalidation), **refactor** vers l’implémentation de référence sans changer les **ports**.

## Conséquences

- Les revues de code vérifient que les exceptions ne se **multiplient** pas sans besoin réel.
- Les tests (DUR015) restent **isolés** : prévoir reset ou instances fraîches par test lorsque l’état est mutable.
