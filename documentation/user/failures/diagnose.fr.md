---
title: Diagnostiquer une activité ou un workflow en échec
weight: 10
---

# Diagnostiquer une activité ou un workflow en échec

Chaque section part de ce que vous observez, donne la cause, puis ce qu'il faut faire. Une activité
est une unité d'effet de bord dans un workflow : un appel HTTP, une écriture en base, un e-mail. Le
journal est l'enregistrement, en ajout seul, de tout ce qu'une exécution a décidé et reçu. Voyez le
[glossaire](../../glossary/).

## Une activité a cessé de réessayer avant le nombre de tentatives attendu

L'événement `ActivityFailed` final indique pourquoi les réessais se sont arrêtés, dans son
`retryState` :

```php
$failed->retryState();     // ActivityRetryState
$failed->isStalled();      // vrai quand les tentatives sont épuisées
```

| `retryState` | Cause | Que faire |
|---|---|---|
| `NonRetryableFailure` | l'exception est déclarée non réessayable | c'est attendu si vous l'avez listée ; sinon, retirez-la de `nonRetryableExceptions` |
| `MaximumAttemptsReached` | toutes les tentatives autorisées ont été utilisées | vérifiez la limite : `RetryLimit::ofAttempts(3)` autorise trois exécutions au total, la première comprise |
| `Timeout` | une borne « planification à démarrage » ou « planification à clôture » s'est écoulée | vérifiez `scheduleToStart` et `scheduleToClose` dans les [`ActivityTimeouts`](../../options/#activitytimeouts) de l'activité |
| `RetryPolicyNotSet` | aucune politique de réessai ne s'appliquait | vérifiez les options de réessai de l'activité ; voyez [`RetryLimit`](../../options/#retrylimit) |

Un échec enregistré avec `InProgress` n'est pas final : une autre tentative est attendue. Il
apparaît quand les réessais sont délégués au serveur Temporal.

## Une activité réessaie sans fin et son workflow n'échoue jamais

Aucune limite de réessais ne s'applique à l'activité. Sans limite explicite, les tentatives sont
**illimitées** : une activité qui échoue à chaque fois est réessayée indéfiniment, et son workflow
n'atteint jamais l'échec.

Fixez une limite sur l'activité ; voyez [Options](../../options/#retrylimit). Pour une exception
qu'aucune tentative ne peut corriger, [arrêtez aussi ses réessais](../stop-retrying/).

## `messenger:failed:retry` sur une activité en échec ne relance rien

Le message présent dans `failure_transport` correspond à un échec **non réessayable**. Durable a
écrit cette tentative dans le journal avant de lever `UnrecoverableMessageHandlingException`.
Relancer le message ne réexécute rien, car la tentative figure déjà dans le journal.

L'échec parvient au workflow comme une activité en échec. Traitez-le là, en l'attrapant ou en
compensant ; voyez [Compenser](../../cancellation/#compenser).

## Les logs affichent *« dropped an early resume of execution »*

La ligne complète, de niveau `info` sur le canal `messenger`, est : *« dropped an early resume of
execution … the resume sent after its append carries the run »*.

L'exécution n'est pas perdue, et rien n'atteint le `failure_transport`. Un worker envoie une
reprise avant de journaliser le fait qu'elle annonce (le résultat d'une activité, un signal, le
résultat d'un enfant, un minuteur échu), puis une autre après. La reprise en avance échoue exprès
et elle est réessayée. Au bout de dix nouvelles livraisons, elle est acquittée et cette ligne est
écrite. La seconde reprise porte l'exécution.

## Un workflow s'est terminé par `WorkflowExecutionFailed`

Le code du workflow n'a pas attrapé un échec. Le champ `kind` indique d'où vient l'échec, par
exemple `unhandled_activity_failure` pour un échec d'activité que le workflow a laissé passer, ou
`workflow_handler_failure` quand le code du workflow a lui-même levé une exception. La liste
complète est dans [Événements et états d'échec](../events/#kind-de-workflowexecutionfailed).

Sur le backend Temporal, l'erreur que vous relisez est un `WorkflowExecutionFailed` typé, avec le
nom de l'activité en échec, et non un simple message.

## Pages liées

- [Événements et états d'échec](../events/)
- [Les réessais avec Symfony Messenger](../messenger/)
