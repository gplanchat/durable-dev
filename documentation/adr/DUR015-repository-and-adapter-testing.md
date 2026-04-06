# DUR015 — Tests des repositories, adaptateurs et données

## Statut

Accepté

## Contexte

Les **ports** Command et Query (DUR002) et leurs **adaptateurs** (DUR012) sont au cœur de l’intégration avec Temporal. Les **erreurs** (DUR011) et la **sérialisation** (DUR007) doivent être validées de façon **fiable** et **répétable**. Les stratégies générales de tests figurent dans DUR009 et DUR010 ; cette ADR précise le **périmètre repository / adaptateur / données de test**.

## Décision

### Objectifs

- Vérifier que chaque **adaptateur** respecte le **contrat** du port (mapping, erreurs traduites, enchaînements d’appels).
- Garantir le **déterminisme** : mêmes entrées simulées → mêmes sorties et mêmes types d’erreur attendus.
- **Isoler** les tests des dépendances réseau lorsque possible (backend **In-Memory** DUR005, ou client de protocole factice).

### Données de test

- Utiliser des **fixtures** ou **builders** dédiés pour les entités et identifiants ; éviter les identifiants aléatoires non fixés.
- Prévoir des jeux **minimalistes** par scénario : **chargement** et **nettoyage** ou **réinitialisation** explicites entre tests lorsque l’implémentation conserve un état mutable.
- Pour les tests nécessitant un **serveur Temporal** réel, limiter leur nombre (DUR010) et documenter les prérequis.

### Stratégie par type de cible

| Cible | Approche privilégiée |
|--------|----------------------|
| Adaptateur repository + client factice | Tests unitaires / intégration légers, assertions sur les appels et les mappers |
| Backend In-Memory complet | Tests d’intégration sans réseau |
| Client bas niveau seul | Tests avec réponses enregistrées ou stub de transport |

### Doubles de test

- Préférer des **fakes** déterministes et des **fixtures** aux mocks configurables à l’infini (alignement avec DUR009 : pas de comportement opaque piloté par setters dynamiques).

### Couverture des erreurs

- Cas **succès** et **échec** (métier vs transitoire, DUR011) pour au moins une opération représentative par port critique.

## Conséquences

- Les **META** peuvent détailler des exemples de structure de dossiers `tests/` sans dupliquer ces principes.
- Les régressions sur le **mapping** sont détectées tôt si les tests restent **stables** et **rapides**.
