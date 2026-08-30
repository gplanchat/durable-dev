---
title: Guide utilisateur
weight: 1
bookFlatSection: false
---

# Guide utilisateur

Comment penser Durable, et comment s'en servir. Commencez par [Pourquoi Durable](why/) si vous
hésitez encore ; la [page d'accueil](/fr/) plaide la même chose, de façon interactive.

| | |
|---|---|
| [Pourquoi Durable](why/) | le problème qu'il résout, ce qu'il remplace — et quand vous n'en avez pas besoin |
| [Paquets](packages/) | la bibliothèque, le bundle, le pilote Temporal — quoi installer, et quand |
| [Premiers pas](getting-started/) | installation, configuration Symfony, un premier workflow, les commandes du worker |
| [Concepts](concepts/) | workflows, activités, rejeu et backends, en français courant |
| [Backends](backends/) | en mémoire ou Temporal, et ce que chacun sait faire |
| [Écrire un workflow](workflows/) | `WorkflowEnvironment`, signaux, requêtes, mises à jour, workflows enfants |
| [Écrire des activités](activities/) | contrats d'activité, injection de dépendances, le stub typé |
| [Échecs et réessais](failures/) | ce que le journal enregistre, et pourquoi une activité a cessé de réessayer |
| [Annulation](cancellation/) | lever l'annulation dans le workflow pour qu'il puisse compenser |
| [Opérations Nexus](nexus/) | appeler une opération servie par une autre équipe — et en servir |
| [Options et objets valeur](options/) | limites de réessai, délais, planifications cron, attributs de recherche |
| [Tester des workflows](testing/) | des tests unitaires sans serveur, et la suite qui tourne contre un vrai |
| [Référence de configuration](configuration/) | chaque clé de `durable.yaml` |

Les décisions d'architecture (**DUR**) et les conventions de travail (**WA**) vivent dans le
dépôt, à destination des contributeurs, sous `documentation/adr/` et `documentation/wa/`.
