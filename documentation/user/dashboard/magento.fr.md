---
title: Le tableau de bord dans Magento
weight: 20
---

# Le tableau de bord dans Magento

Pour suivre les workflows d'une boutique depuis l'administration Magento, installez
`gplanchat/durable-magento` ([Paquets](../../packages/)) et ouvrez **System > Durable processes >
Process history**. La page est en lecture seule et en anglais.

## Donner l'accès à un rôle

L'écran a sa propre ressource ACL. Dans **System > Permissions > User Roles**, cochez **Durable
processes > Process history** dans les ressources du rôle.

## Ce que la page propose

- **L'état du backend**, daté, avec une ligne par rôle de worker (journal et activité) quand
  Temporal tient le [journal](../../glossary/) (les étapes enregistrées d'une exécution et leurs résultats).
- **Des compteurs** par issue, sur les 200 exécutions les plus récentes, quels que soient les
  filtres de la grille.
- **La grille standard de l'administration** : pagination (20 par défaut), contrôle des colonnes,
  et filtres sur l'état, le nom du workflow, l'identifiant d'exécution et l'identifiant d'exécution
  côté backend. Les filtres texte cherchent le texte n'importe où dans la valeur, parmi les exécutions de la fenêtre. Le filtre sur le nom du workflow ignore la casse ; les deux filtres sur les identifiants respectent le texte tel que saisi. Un avis indique la fenêtre quand elle est pleine.
- **Une page d'exécution**, ouverte depuis une ligne : l'exécution, son exécution côté backend,
  l'état, les dates de démarrage et de fin, ce qu'elle attend, ses opérations Nexus, une frise et un tableau **Journal** avec une ligne par événement (nature, phase, action, ce qui s'est passé). Voir
  [Lire une exécution](../reading-a-run/).

## Ce qu'elle ne montre pas

La grille n'affiche pas `waiting for a worker`, ni en ligne ni en nombre. La fenêtre couvre 200
exécutions : une exécution plus ancienne n'apparaît pas, et les compteurs ne la comptent pas non
plus. Voir [Parité](../parity/).

## Contenus enregistrés

Un contenu enregistré se déplie à la demande. Il est masqué par la préférence que votre application
déclare pour `Gplanchat\Durable\Observation\PayloadRedactorInterface` ; le module fournit le masquage
par nom de clé.
