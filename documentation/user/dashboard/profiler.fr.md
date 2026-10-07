---
title: Le panneau du profileur web de Symfony
weight: 30
---

# Le panneau du profileur web de Symfony

Pour voir quels workflows une requête a envoyés et ce que leurs [journaux](../../glossary/)
(l'historique des étapes d'une exécution et de leurs résultats) ont enregistré jusque-là, ouvrez le
panneau **Durable** du profileur web. Il appartient à `gplanchat/durable-bundle` et apparaît partout
où le profileur web est installé.

Ce n'est pas le [tableau de bord](../) dans un cadre plus petit. Le tableau de bord liste les
exécutions d'une application ; ce panneau liste les exécutions d'**une requête**, il n'a donc ni
bandeau d'état du backend, ni compteurs, ni filtres.


## Captures d'écran

![L'onglet Summary du panneau Durable : deux exécutions de la requête, une terminée et une en cours](/images/dashboard/profiler-summary.png)

*L'onglet Summary après une requête qui a envoyé deux workflows : un terminé, un en cours dont le journal ne contient pas encore d'événement.*

![L'onglet Executions du panneau Durable avec l'historique de l'exécution terminée](/images/dashboard/profiler-executions.png)

*L'onglet Executions, ouvert sur l'exécution terminée : son issue, puis son historique, une ligne par événement du journal.*

## Ouvrir le panneau

1. Chargez une page de votre application qui envoie un workflow, avec la barre de débogage activée.
2. Cliquez sur l'élément Durable de la barre d'outils. Il affiche le nombre d'envois et
   d'événements de journal collectés sur la requête.
3. Lisez d'abord l'onglet **Summary** : une ligne par exécution, avec son identifiant d'exécution,
   son type, son issue et son nombre d'événements.

L'onglet **Executions** ouvre chaque exécution : son historique d'événements, ses opérations Nexus,
sa frise et sa trace de processus. La frise est celle des pages d'exécution, avec une ligne par
action, l'attente d'un worker hachurée et l'intervalle en échec en rouge (voir
[Lire une exécution](../reading-a-run/)). La trace de processus montre le temps que ce processus a
passé sur l'exécution pendant la requête, que le journal n'enregistre pas. L'onglet **Overview**
dessine tous les processus sur une même échelle de temps et liste les envois Messenger de la
requête.

## Charger un journal que la requête n'a pas envoyé

Le panneau collecte les identifiants d'exécution vus dans la trace de processus de la requête : les envois Messenger, et les workflows et activités exécutés dans le même processus.
Pour en ajouter un qu'un worker ou une autre requête a démarré, placez `durable_execution` dans
l'URL de la requête que vous profilez, avec l'identifiant d'exécution, ou plusieurs séparés par des
virgules :

```
https://shop.localhost/checkout?durable_execution=0195f3c2-7a1e-7d2a-9c1b-3f6a2e8b5d40
```

Le paramètre se place sur la requête profilée elle-même, celle qui remplit la barre d'outils. Le
mettre ensuite sur l'URL du profileur ne change rien. Rechargez la page avec lui pour produire un
nouveau profil. Une requête lit au plus 20 identifiants, et chaque identifiant coûte une lecture de
journal.

## Quand le journal est vide

Si la requête a envoyé un message mais que le panneau n'affiche aucun événement, le message est
probablement encore en file : avec un transport asynchrone, le gestionnaire ne s'est pas exécuté
dans ce processus. Le panneau affiche **Journal still empty**. Lancez un worker, puis rechargez avec
`durable_execution` renseigné.

## Ce qui diffère du tableau de bord

- **Vocabulaire.** La colonne d'issue emploie les mots des autres tableaux de bord et ajoute trois états qui ne sont pas des issues : Queued (no journal yet), Pending et Cancellation requested. Le panneau est en anglais seulement.
- **Limites.** 500 événements par journal, et un avertissement quand une table d'opérations Nexus
  est tronquée.

[Parité](../parity/) liste chaque différence.
