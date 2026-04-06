# DUR003 — Machine à états, rejeu, fibres et awaitables

## Statut

Accepté

## Contexte

Un **workflow** dans le composant Durable n’est pas une simple fonction : il doit pouvoir **s’interrompre** aux points d’attente (activités, timers, etc.), **persister** sa progression via l’historique Temporal, et **rejouer** de façon déterministe le même code jusqu’au dernier point exécuté. Les langages PHP modernes offrent des **Fibers** pour suspendre et reprendre l’exécution ; le composant s’appuie sur ce mécanisme pour modéliser l’exécution interruptible.

Par ailleurs, le workflow ne doit pas effectuer d’**I/O** direct : seules les **activités** (DUR004) portent les effets de bord.

## Décision

### StateMachine et EventStore

- Une **StateMachine** (ou équivalent nommé dans l’implémentation) **consomme les événements** fournis par l’EventStore (DUR001) dans l’ordre.
- Elle **rejoue** le workflow jusqu’au dernier événement connu, de manière à recréer la même séquence de décisions qu’à l’exécution initiale.

### Fibers et interruption

- L’exécution du code utilisateur du workflow s’effectue dans un modèle **interruptible** basé sur des **Fibers** : aux points où le workflow attend un résultat externe, la fiber peut être suspendue et reprise lors du rejeu ou de la livraison du résultat.

### Contexte workflow et awaitables

Le **constructeur** du workflow reçoit un **contexte** fourni par le runtime du composant. Ce contexte expose des méthodes pour composer les attentes **sans** bloquer le thread global de façon incompatible avec le modèle durable :

- `await` — attendre un awaitable du composant ;
- `parallel` — lancer plusieurs branches en parallèle logique ;
- `all` — attendre la complétion de toutes les branches ;
- `race` — compétition entre branches (première complétion pertinente selon la sémantique définie) ;
- `any` — sélection / première réponse utile parmi des alternatives (sémantique précisée par l’implémentation) ;
- `resolve` / `reject` — clôture d’une promesse ou équivalent interne au modèle awaitable du composant.

*(Les noms exacts et la granularité des API peuvent être ajustés à l’implémentation, mais la séparation « contexte injecté » / « pas d’I/O dans le workflow » reste obligatoire.)*

Les **awaitables** sont des primitives **spécifiques au composant** : elles matérialisent les points de synchronisation avec l’historique (activités, timers, etc.) de façon compatible avec le rejeu.

### Idempotence

- Un workflow **doit être idempotent** au sens **déterministe** : pour un même flux d’événements, le code du workflow produit les mêmes enchaînements de décisions et les mêmes nouvelles commandes vers l’orchestrateur. Aucune dépendance au temps réel, à l’aléatoire non enregistré, ni aux I/O dans le corps du workflow.

## Conséquences

- La documentation des awaitables et du contexte est centrale pour les auteurs de workflows ; toute évolution doit préserver la compatibilité de rejeu.
- Les tests peuvent s’appuyer sur le backend In-Memory (DUR005) pour valider le déterminisme sans infrastructure lourde.
