# DUR010 — Pyramide des tests

## Statut

Accepté

## Contexte

Un **surtout** de tests lents ou fragiles (UI, réseau, infrastructure lourde) ralentit le feedback et masque les régressions. Une **pyramide des tests** équilibrée place la majorité de la confiance dans **beaucoup** de tests **rapides** et **fiables** en base, et **peu** de tests **coûteux** au sommet.

## Décision

Le projet Durable adopte une **pyramide des tests** alignée sur les couches du composant :

### Base — tests unitaires (majorité)

- **Cible** : logique pure, value objects, state machine, règles de sérialisation, transformations sans I/O.
- **Caractère** : rapides, isolés, sans base ni Temporal.
- **Objectif** : couvrir les **chemins** et **invariants** (déterminisme, rejeu simulé) à faible coût.

### Milieu — tests d’intégration

- **Cible** : **ports** avec le backend **In-Memory** (DUR005), enchaînements repository + EventStore + stubs d’activité selon le périmètre du composant.
- **Caractère** : plus lents que l’unitaire, mais **sans** infrastructure externe obligatoire.
- **Objectif** : valider le **câblage** et les **contrats** entre modules internes.

### Sommet — tests end-to-end ou système (minorité)

- **Cible** : scénarios complets avec **Temporal** réel (ou environnement de test dédié) lorsque nécessaire pour valider la **compatibilité réseau/protocole** et les **chemins critiques** non couverts par In-Memory.
- **Caractère** : les plus lents et les plus sensibles à l’environnement ; **nombre limité**.
- **Objectif** : confiance sur l’intégration **réelle**, pas duplication de toute la couverture unitaire.

### Principes

- **Ne pas** inverser la pyramide : éviter une majorité de tests E2E lents pour le détail métier.
- Les **régressions** détectées en E2E devraient **idéalement** faire l’objet d’un test **plus bas** dans la pyramide, pour éviter la répétition.

### Relation avec DUR009

- Les **règles d’écriture** (déterminisme, doubles, PHPUnit) s’appliquent à chaque niveau ; la pyramide fixe **où** placer l’effort **relatif**.

## Conséquences

- Les pipelines CI peuvent **séparer** les jobs (unitaires rapides, intégration, E2E optionnel ou planifié).
- L’évolution du composant doit **préserver** la possibilité de tester massivement via **In-Memory** pour ne pas dépendre du sommet pour chaque changement.
