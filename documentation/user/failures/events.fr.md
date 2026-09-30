---
title: Événements et états d'échec
weight: 30
---

# Événements et états d'échec

Référence de ce que Durable enregistre quand une activité ou un workflow échoue. Une activité est
une unité d'effet de bord dans un workflow : un appel HTTP, une écriture en base, un e-mail. Le
journal est l'enregistrement, en ajout seul, de tout ce qu'une exécution a décidé et reçu. Voyez le
[glossaire](../../glossary/).

Une activité en échec écrit plusieurs événements. Leur lecture vous indique s'il faut compenser,
lever une alerte ou laisser le workflow échouer.

## Événements du journal pour une activité

| Événement | Sens |
|---|---|
| `ActivityScheduled` | le workflow a demandé l'activité |
| `ActivityTaskStarted` | une tentative a commencé ; une ligne par tentative |
| `ActivityTaskFailed` | **une tentative a échoué**, qu'une autre tentative suive ou non |
| `ActivityTaskCompleted` | seulement dans les journaux écrits avant #262 ; un succès n'écrit plus que `ActivityCompleted` |
| `ActivityCompleted` | résultat final : succès |
| `ActivityFailed` | résultat final : échec |
| `ActivityCancelled` | résultat final : retirée avant d'aboutir |
| `ActivityCatastrophicFailure` | l'échec lui-même n'a pas pu être journalisé sans risque |

`ActivityTaskFailed` est écrit pour chaque tentative en échec, y compris quand une tentative
suivante réussit. Avant cet événement, une tentative en échec suivie d'un succès ne laissait aucune
trace dans le journal.

## `retryState` de `ActivityFailed`

`ActivityFailed` porte un `retryState` qui indique laquelle des quatre situations a mis fin aux
réessais. Les versions précédentes enregistraient les quatre de la même façon.

```php
use Gplanchat\Durable\Failure\ActivityRetryState;

$failed->retryState();     // ActivityRetryState
$failed->isStalled();      // vrai quand les tentatives sont épuisées
```

| État | Sens |
|---|---|
| `NonRetryableFailure` | l'exception est déclarée non réessayable et n'est jamais retentée |
| `MaximumAttemptsReached` | toutes les tentatives autorisées ont été utilisées |
| `Timeout` | une borne « planification à démarrage » ou « planification à clôture » s'est écoulée |
| `RetryPolicyNotSet` | aucune politique de réessai ne s'appliquait |
| `InProgress` | non final ; une autre tentative est attendue |

`InProgress` enregistre un échec qui n'est **pas** un dénouement. Il apparaît quand les réessais
sont délégués au serveur Temporal. Il ne compte pas, délibérément, comme un dénouement terminal, et
la tentative suivante a donc lieu.

Cet état reprend le `RetryState` de Temporal, qui est lui aussi un champ de l'échec et non un type
d'événement distinct.

## Décompte des tentatives

`RetryLimit::ofAttempts(3)` autorise **trois exécutions au total**, la première comprise, comme
sur Temporal.

Sans limite explicite, les tentatives sont **illimitées**. Une activité qui échoue à chaque fois
est réessayée indéfiniment, et son workflow n'échoue jamais. Voyez
[Options](../../options/#retrylimit).

## `kind` de `WorkflowExecutionFailed`

Un échec que le code du workflow n'attrape pas termine l'exécution avec `WorkflowExecutionFailed`.
Son champ `kind` donne l'origine de l'échec :

| Genre | Origine |
|---|---|
| `unhandled_activity_failure` | un échec d'activité que le workflow n'a pas attrapé |
| `unhandled_declared_activity_failure` | un échec métier déclaré que le workflow n'a pas attrapé |
| `unhandled_catastrophic_activity_failure` | un échec d'activité qui n'a pas pu être journalisé |
| `unhandled_activity_superseded` | le workflow a attendu une activité qui avait perdu une course |
| `workflow_handler_failure` | le code du workflow a lui-même levé une exception |
| `terminated_by_parent` | un parent s'est fermé avec `ParentClosePolicy::Terminate` |

Sur le backend Temporal, ce genre est stocké dans les détails d'`ApplicationFailureInfo`. La
relecture de l'historique reconstruit un `WorkflowExecutionFailed` typé au lieu d'un simple
message, et conserve le nom de l'activité en échec.

## Pages liées

- [Diagnostiquer une activité ou un workflow en échec](../diagnose/)
- [Arrêter les réessais pour une exception](../stop-retrying/)
- [Les réessais avec Symfony Messenger](../messenger/)
- [Compenser](../../cancellation/#compenser)
