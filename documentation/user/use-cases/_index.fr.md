---
title: Cas d'usage
weight: 45
---

# Cas d'usage

Le reste de ce guide est de la référence : une page par fonctionnalité, `await` ici, les signaux
là, Nexus plus loin. Cette section fait l'inverse. Chaque entrée est **une chose entière** —
plusieurs applications, plusieurs mécanismes, un problème qui existe en dehors de Durable — avec
son code dans le dépôt et de quoi la lancer.

Ce ne sont pas des exercices avancés. Aucune n'est difficile ; elles sont *complètes*. C'est la
seule chose qui les distingue d'un exemple de la section [Écrire un workflow](../workflows/).

| | |
|---|---|
| [Quatre applications qui s'appellent](nexus-demo/) | trois frameworks, quatre namespaces Temporal, un contrat partagé — et une exécution qui sert une opération pendant qu'elle en appelle une autre |
| [Un agent IA interruptible](durable-agent/) | la boucle d'agent de Symfony AI pilotée depuis du code de workflow : elle survit au redémarrage, et elle attend votre accord avant d'envoyer le courriel |

## Ce qu'une entrée doit contenir

Pour que la section reste lisible quand elle grandira, chaque page dit, dans cet ordre :

1. **le problème**, formulé sans le mot « Durable » ;
2. **ce qui est construit** — les fichiers, où ils sont ;
3. **ce que Durable apporte**, et surtout **ce qu'il n'apporte pas** ;
4. **comment on la lance** ;
5. **ce qui n'est pas prouvé.** Une entrée sans cette partie est une brochure.
