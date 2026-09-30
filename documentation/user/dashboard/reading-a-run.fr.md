---
title: Lire une exécution
weight: 50
---

# Lire une exécution

Pour savoir ce qui est arrivé à une exécution, ouvrez sa page. Sylius, Magento et Filament dessinent
la même page, dans l'habillage de leur propre administration. Le [panneau du profileur
web](../profiler/) montre les mêmes faits dans une autre disposition.

## L'en-tête

L'en-tête nomme le workflow, l'identifiant d'exécution et l'issue : en cours, terminée, en échec,
annulée ou poursuivie à neuf (*continued as new* dans Magento). Deux cas demandent une seconde
lecture.

- **Poursuivie à neuf** est une fin normale. L'exécution a passé la main à une exécution neuve et
  s'est terminée sans erreur.
- **Deux identifiants.** Sur Temporal, l'identifiant d'exécution est celui que votre application
  connaît, et l'identifiant de run désigne une tentative côté backend. Sylius et Filament affichent
  l'identifiant de run à côté du premier, et Magento lui donne sa propre ligne, **Backend run**.

Une exécution en cours ajoute une ligne qui dit ce qu'elle attend, à sa dernière suspension :
`waiting on timer "grace period" due at 2026-09-24T10:00:00+00:00`,
`waiting on activity charge attempt 2 in flight`, ou `waiting on condition at src/…/OrderWorkflow.php:42`.
Une exécution envoyée et pas encore prise en charge affiche `waiting for a worker` à la place, avec
le temps qu'elle a attendu.

## La frise

Chaque ligne est une **action** : une activité, un minuteur, un workflow enfant, une opération Nexus,
un signal reçu. Une activité planifiée, démarrée puis terminée occupe une ligne, et non trois. La
première ligne est l'exécution elle-même.

- **La barre est la durée**, placée là où l'action a eu lieu, d'après l'heure enregistrée.
- **Un intervalle hachuré est une file.** Le travail avait été demandé et personne ne l'avait
  commencé. Une exécution qui a passé 22 de ses 24 secondes en hachuré attendait un worker.
- **Le rouge marque l'événement qui a échoué.** Une activité qui a échoué deux fois puis réussi porte
  du rouge et se termine bien. Une annulation n'est pas en rouge.
- **Le nom sur la ligne** est celui de l'activité, du workflow enfant ou de l'opération. Un minuteur
  est nommé par son délai.

Le profileur web ne dessine pas cette frise : il liste une ligne par événement.

## Les événements

Sous la frise, chaque action liste ses événements avec une phase : demandé, pris en charge, en échec
ou réglé (*requested*, *started*, *failed*, *settled* dans Magento). Cliquez sur un événement pour
déplier ce que le backend a enregistré avec lui : les arguments d'appel d'une activité, ce qu'elle a
renvoyé, la classe et le message d'un échec. Les valeurs rangées sous des clés comme `password`,
`token`, `secret`, `authorization`, `card` ou `api_key` sont remplacées, et les longues chaînes sont
tronquées. Les données personnelles rangées sous d'autres clés restent visibles : traitez la page
comme vous traitez le journal.

Un événement sans rien d'enregistré reste une ligne simple.

## Les opérations Nexus

Quand l'exécution a appelé une [opération Nexus](../../nexus/), un tableau liste son endpoint, son
service, son opération et son état : en cours, terminée, en échec, expirée ou annulée. Une opération en cours
est une attente servie par un autre service : la cause d'une exécution lente peut s'y trouver plutôt
que dans votre code.

## Voir aussi

- [Une exécution n'avance pas](../run-not-progressing/) part de ce que dit la page et mène à ce qu'il
  faut faire
- [Parité](../parity/) dit quelle surface montre lesquels de ces éléments
