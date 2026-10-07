---
title: Le tableau de bord dans Sylius
weight: 10
---

# Le tableau de bord dans Sylius

Pour suivre les workflows de votre boutique depuis le back-office Sylius, installez
`gplanchat/durable-plugin` ([Paquets](../../packages/)) et ouvrez **Configuration > Tableau de bord
Durable**. La page est en lecture seule.

## Captures d'écran

![Le tableau de bord Sylius : état du backend, compteurs par issue, filtres et liste des exécutions](/images/dashboard/sylius-runs.fr.png)

*Configuration > Tableau de bord Durable, sur quatre exécutions d'un workflow de commande : une terminée, une en échec, une en attente d'un minuteur et une en attente d'un worker.*

![La page d'une exécution en attente d'un minuteur, avec sa frise d'historique](/images/dashboard/sylius-run.fr.png)

*La page d'order/4244. La partie hachurée de `reserveStock` est le temps que la tâche a passé dans la file avant qu'un worker la prenne en charge ; le minuteur qui suit est posé et n'a pas expiré.*

![La page d'une exécution en échec, avec l'activité en échec en rouge](/images/dashboard/sylius-run-failed.fr.png)

*La page d'order/4243 : `chargePayment` a échoué, et l'exécution avec elle.*

## Ce que la page propose

- **L'état du backend**, au-dessus de tout, daté, avec une ligne par rôle de worker quand Temporal
  tient le [journal](../../glossary/) (les étapes enregistrées d'une exécution et leurs résultats).
  Sous le backend DBAL, une ligne affiche « Impossible de demander » : Messenger ne tient aucune
  liste des processus qui lancent `bin/console durable:worker`. Le backend en mémoire n'affiche
  aucune ligne de worker.
- **Des compteurs** par issue, avec un nombre **En attente d'un worker**, sur les exécutions de la
  page.
- **Un filtre** sur l'issue, et sur le nom du workflow et le début de l'identifiant d'exécution là
  où le backend sait les appliquer.
- **La liste des exécutions**, 20 par page, en avant par curseur, avec un lien **Première page**.
  Chaque ligne porte l'issue, le workflow, l'identifiant d'exécution et une note :
  `waiting for a worker · 42 s`, ou `waiting on timer "grace period"…`.
- **Une page d'exécution**, ouverte depuis une ligne : le workflow, l'identifiant d'exécution,
  l'issue, ses [opérations Nexus](../../nexus/), et son historique, un bloc par action, sous une frise. Voir [Lire
  une exécution](../reading-a-run/).

## Adresses

La liste se trouve à `<admin path>/durable/runs` et une exécution à `<admin path>/durable/runs/<execution
id>`. L'ancienne adresse `<admin path>/durable/dashboard` redirige vers la liste ou vers
l'exécution : les signets restent valables.

## Langue et contenus enregistrés

La page existe en anglais et en français. Un contenu enregistré se déplie à la demande et est masqué
par le service que votre application déclare comme alias de
`Gplanchat\Durable\Observation\PayloadRedactorInterface` ; sans alias, le masquage par nom de clé
décrit dans la [vue d'ensemble](../) s'applique.

## Différences connues

La page d'une exécution ne reprend pas la ligne `waiting on` de la liste, et la date de démarrage
d'une exécution qui n'en a pas reste vide dans la grille. Voir [Parité](../parity/).
