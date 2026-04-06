# DUR001 — EventStore et parcours par curseur

## Statut

Accepté

## Contexte

Pour rejouer et inspecter l’historique d’exécution d’un workflow durable, il faut exposer les **événements** persistés côté orchestrateur (Temporal) sous une forme exploitable par le composant, sans charger l’historique entier en mémoire lorsque celui-ci est volumineux.

## Décision

Le composant Durable expose un **EventStore** qui :

1. **Lit** l’historique d’événements associé à un workflow Temporal (identifié de façon stable par les primitives du modèle du composant : ex. identifiant de workflow, run, namespace selon les conventions fixées par l’implémentation).
2. **Fournit** les événements sous forme de **liste itérable** avec **pagination par curseur** : le consommateur obtient un lot d’événements et un curseur (opaque pour l’appelant) permettant de demander le lot suivant jusqu’à épuisement.

### Principes

- **Ordre total** : les événements sont parcourus dans l’ordre chronologique (ou l’ordre défini par l’orchestrateur) de façon déterministe pour le rejeu.
- **Curseur opaque** : le client ne décode pas la structure interne du curseur ; il le repasse tel quel pour la page suivante.
- **Performance** : éviter le parcours par offset sur de grands historiques ; le modèle curseur vise une complexité stable par requête.
- **Cohérence** : lors d’une lecture paginée, la sémantique doit éviter les doublons et les trous liés aux lectures concurrentes autant que le permet l’API sous-jacente (comportement documenté en cas de limite).

### Rôle dans l’architecture

L’EventStore est la **source de vérité** pour la reconstruction du comportement du workflow via la machine à états (voir DUR003) : le rejeu consiste à réappliquer les événements jusqu’au dernier point exécuté.

## Conséquences

- Les adaptateurs Temporal et In-Memory (DUR005) doivent chacun fournir une implémentation cohérente de ce contrat.
- Les types d’événements exposés doivent être suffisamment riches pour alimenter la StateMachine sans ambiguïté.
