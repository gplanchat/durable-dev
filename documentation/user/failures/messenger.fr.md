---
title: Les réessais avec Symfony Messenger
weight: 40
---

# Les réessais avec Symfony Messenger

Quand les activités passent par Symfony Messenger, deux systèmes pourraient réessayer une activité
en échec. Cette page explique comment le travail se répartit entre eux, et pourquoi un échec
d'activité n'a qu'un seul compteur de réessais. Une activité est une unité d'effet de bord dans un
workflow : un appel HTTP, une écriture en base, un e-mail. Le journal est l'enregistrement, en
ajout seul, de tout ce qu'une exécution a décidé et reçu. Voyez le [glossaire](../../glossary/).

## Quel système possède chaque réglage de réessai

Durable décide si une activité est réessayée, et quand. Messenger achemine les messages.

| Question | Décidé par | Réglage |
|---|---|---|
| Cet échec vaut-il une nouvelle tentative ? | Durable | `nonRetryableExceptions` sur `ActivityOptions` |
| Combien de tentatives, et quel délai entre elles ? | Durable | `retryLimit`, `initialInterval`, `backoffCoefficient`, `maximumInterval` ; `max_activity_retries` les plafonne tous, sauf sous Temporal |
| Où va une activité en échec pour qu'un opérateur la retrouve ? | Messenger | `failure_transport` |
| Acheminement, acquittement, le transport lui-même | Messenger | le transport `durable_activities` |

## Un seul compteur de réessais

Durable planifie ses propres réessais. Une tentative réessayée est un nouveau message, mis en file
avec son délai. Le handler ne lève pas d'exception pour un échec que Durable réessaie : le
`retry_strategy` de Messenger ne s'applique donc pas aux échecs d'une activité, et il n'y a pas de
second compteur à tenir en phase avec le premier.

Un échec **non réessayable** suit un autre chemin. Durable l'écrit dans le journal, puis le lève en
`UnrecoverableMessageHandlingException`. Messenger ne le réessaie pas, et l'envoie au
`failure_transport` si vous en avez configuré un. Lancer `messenger:failed:retry` sur ce message
ne réexécute rien, car la tentative figure déjà dans le journal.

## Deux messages qui passent outre `max_retries`

Deux sortes de messages passent outre `max_retries`, exprès. Chacun attend une chose qu'un autre
worker règle.

**Une tentative d'activité qu'un autre worker exécute.** Sur un journal DBAL, Durable réserve
chaque tentative avant de l'exécuter. Laravel fait de même ; un journal en mémoire tourne dans un
seul processus et ne réserve rien. Une copie qui trouve la réservation prise est réessayée plus
tard, selon le `retry_strategy` du transport. Elle trouve alors la tentative dans le journal ou,
si le worker qui détenait la réservation s'est arrêté, exécute la tentative une fois la réservation
expirée.

**Une reprise arrivée avant le fait qu'elle annonce.** Un worker envoie une reprise avant de
journaliser ce qu'elle annonce (le résultat d'une activité, un signal, le résultat d'un enfant, un
minuteur échu), puis une autre après. La reprise en avance échoue exprès et elle est réessayée. Au
bout de dix nouvelles livraisons, elle est acquittée, et le canal `messenger` écrit une ligne
`info` : *« dropped an early resume of execution … the resume sent after its append carries the
run »*. L'exécution n'est pas perdue, et rien n'atteint le `failure_transport`.

## Pages liées

- [Arrêter les réessais pour une exception](../stop-retrying/)
- [Diagnostiquer une activité ou un workflow en échec](../diagnose/)
- [Événements et états d'échec](../events/)
