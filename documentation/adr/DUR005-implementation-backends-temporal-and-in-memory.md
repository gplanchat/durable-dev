# DUR005 — Backends d’implémentation : Temporal et In-Memory

## Statut

Accepté

## Contexte

Le composant Durable doit s’intégrer à un orchestrateur réel pour la production, tout en restant **testable** et utilisable en **développement** sans dépendances lourdes. Les contraintes du projet excluent certains runtimes (voir DUR006).

## Décision

Pour l’horizon **court et moyen terme**, seuls deux backends d’implémentation sont **en périmètre** :

1. **Temporal** — orchestration réelle, persistance de l’historique, workers conformes au protocole attendu par le composant (sans SDK officiel PHP interdit par DUR006).
2. **In-Memory** — simulation locale des mêmes **ports** (EventStore, repositories Command/Query, comportement de rejeu / stubs) pour les tests et les prototypes.

### Principes

- Les deux backends exposent les **mêmes abstractions** (ports) documentées dans DUR001, DUR002, DUR003 et DUR004.
- Aucun troisième backend « officiel » (autre moteur de workflow, autre broker) n’est ciblé dans ce périmètre ; son ajout ultérieur serait l’objet d’**ADR nouvelles**.

### Rôle respectif

- **Temporal** : vérité opérationnelle, scalabilité, observabilité fournie par la stack Temporal.
- **In-Memory** : rapidité de feedback, déterminisme contrôlé dans les tests, absence de réseau.

## Conséquences

- Les évolutions qui ne peuvent être portées que par Temporal doivent rester derrière des interfaces pour ne pas casser In-Memory.
- La documentation et les exemples peuvent supposer l’un ou l’autre backend sans impliquer un troisième.
