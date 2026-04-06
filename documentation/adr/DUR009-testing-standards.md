# DUR009 — Règles d’écriture des tests

## Statut

Accepté

## Contexte

Le composant Durable repose sur des **comportements déterministes** (rejeu, idempotence des workflows) et des **adaptateurs** (Temporal, In-Memory). Les tests doivent **protéger** ces contrats sans fragilité, sans dépendre de l’aléa ou du temps réel, et sans accumuler des doubles de test opaques.

## Décision

### Cadre et outillage

- **PHPUnit** est le framework de test par défaut pour le code PHP du projet.
- Les tests **doivent** être **déterministes** : pas de dépendance à l’heure réelle, aux identifiants aléatoires non contrôlés, ni aux réseaux externes non simulés.

### Organisation

- **Un cas de test** (méthode) **une intention** : nom de test lisible décrivant le comportement attendu.
- **Données** : préférer des **fixtures** ou **builders** explicites plutôt que des littéraux magiques répétés ; facteurs communs dans des méthodes ou classes de données de test dédiées lorsque cela clarifie la lisibilité.

### Doubles et isolation

- **Test doubles** (fakes, stubs, spies) **préférés** aux mocks généralistes lorsque la lisibilité et le contrôle du comportement sont meilleurs.
- Les **mocks** ne sont **pas** interdits, mais leur usage doit rester **limité** aux frontières où l’injection d’un comportement est le plus simple.
- Les tests du **domaine** et des **ports** ne doivent **pas** dépendre d’un cluster Temporal réel : utiliser le backend **In-Memory** (DUR005) ou des doubles dédiés.

### Temporal et workflows

- **Pas de SDK Temporal officiel** dans les tests non plus (DUR006) : les tests valident les **abstractions** du composant, pas un client tiers interdit.
- Les scénarios de **rejeu** et d’**idempotence** (DUR003) sont couverts par des tests **répétables** (même entrée → même historique / même décision simulée).

### Style et conventions

- Le code des tests suit le **PER** (DUR008) : nommage des classes de test, méthodes, et structure des fichiers conformes au projet.

### Non-objectifs de cette ADR

- La **proportion** des types de tests (unitaires, intégration, bout-en-bout) est définie dans **DUR010** (pyramide des tests).

## Conséquences

- La CI **devrait** exécuter la suite PHPUnit sur les changements pertinents et exiger une couverture minimale ou des critères de qualité fixés par les mainteneurs.
- Les META documents peuvent détailler des patterns (fixtures, builders) sans dupliquer les principes ci-dessus.
