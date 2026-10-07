---
title: Le tableau de bord dans Magento
weight: 20
---

# Le tableau de bord dans Magento

Pour suivre les workflows d'une boutique depuis l'administration Magento, installez
`gplanchat/durable-magento` ([Paquets](../../packages/)) et ouvrez **System > Durable processes >
Process history** (**Exécutions Durable > Historique des exécutions** pour un compte
d'administration en français). La page est en lecture seule. Elle s'affiche en anglais ou en
français, selon la langue de l'interface (**Interface Locale**) du compte d'administration. Les textes que compose le
cœur, comme les libellés d'événements et les valeurs `waiting on …`, restent en anglais, comme sous
Sylius et Filament.

## Captures d'écran

![La grille de l'historique des processus Magento : état du backend, présence des workers, compteurs par issue et liste des exécutions](/images/dashboard/magento-history.png)

*System > Durable processes > Process history, sur huit exécutions du banc : quatre terminées, deux en échec et deux en cours, dont une suspendue sur un minuteur.*

![La page d'une exécution terminée sous Magento, avec sa frise History](/images/dashboard/magento-run.png)

*La page d'order/4244 : l'exécution, l'exécution côté backend et l'issue, puis une frise History où `durable.demo.charge` est hachuré pendant les 30 secondes d'attente d'un worker.*

## Donner l'accès à un rôle

L'écran a sa propre ressource ACL. Dans **System > Permissions > User Roles**, cochez **Durable
processes > Process history** dans les ressources du rôle.

## Ce que la page propose

- **L'état du backend**, daté, avec une ligne par rôle de worker (journal et activité) quand
  Temporal tient le [journal](../../glossary/) (les étapes enregistrées d'une exécution et leurs résultats).
- **Des compteurs** par issue, sur les 200 exécutions les plus récentes, quels que soient les
  filtres de la grille.
- **La grille standard de l'administration** : pagination (20 par défaut), contrôle des colonnes,
  et filtres sur l'issue, le nom du workflow, l'identifiant d'exécution et l'identifiant d'exécution
  côté backend. Les filtres texte suivent la règle des autres surfaces, parmi les exécutions de la fenêtre : le nom entier du workflow, le début de l'identifiant d'exécution et de l'identifiant de run côté backend, le tout tel que saisi. Un avis indique la fenêtre quand elle est pleine.
- **Une page d'exécution**, ouverte depuis une ligne : l'exécution, son exécution côté backend,
  l'issue, les dates de démarrage et de fin, ce qu'elle attend, ses opérations Nexus, une frise **History** et un tableau **Journal** avec une ligne par événement (type, phase, action, ce qui s'est passé). Voir
  [Lire une exécution](../reading-a-run/).

## Ce qu'elle ne montre pas

La grille n'affiche pas `waiting for a worker`, ni en ligne ni en nombre. La fenêtre couvre 200
exécutions : une exécution plus ancienne n'apparaît pas, et les compteurs ne la comptent pas non
plus. Voir [Parité](../parity/).

## Contenus enregistrés

Un contenu enregistré se déplie à la demande. Il est masqué par la préférence que votre application
déclare pour `Gplanchat\Durable\Observation\PayloadRedactorInterface` ; le module fournit le masquage
par nom de clé.
