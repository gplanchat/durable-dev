# DUR006 — Absence de SDK Temporal officiel PHP et de RoadRunner

## Statut

Accepté

## Contexte

L’écosystème Temporal pour PHP propose couramment un **SDK officiel** et l’usage de **RoadRunner** comme worker. Le projet Durable vise une **maîtrise des dépendances** et une **couche d’intégration** alignée sur des abstractions propres (ports/adapters), sans enfermer l’architecture dans ces stacks.

## Décision

Il est **interdit** d’introduire ou de conserver dans le périmètre du composant Durable :

- le **SDK Temporal officiel** pour PHP (client, worker, helpers livrés par Temporal pour PHP, etc.) ;
- **RoadRunner** et tout composant **dépendant de RoadRunner** ou requis par celui-ci.

Les intégrations avec Temporal passent par des **moyens acceptés par le projet** : appels vers l’API du serveur Temporal (gRPC/HTTP selon les choix d’implémentation), clients maintenus dans le dépôt, ou autres adaptateurs conformes aux ADR DUR001–DUR005.

### Rapport avec les autres ADR

- Les **repositories** (DUR002) et l’**EventStore** (DUR001) ne s’appuient pas sur des types du SDK officiel comme contrat public.
- Les **workflows** et **activités** (DUR003, DUR004) sont modélisés par le composant ; le worker d’exécution n’est pas RoadRunner.

## Conséquences

- Toute dépendance Composer ou tout module qui tire ces technologies doit être rejeté ou remplacé.
- Les guides d’installation et d’exploitation doivent documenter le runtime retenu (hors RoadRunner) lorsque l’implémentation sera réalisée.
