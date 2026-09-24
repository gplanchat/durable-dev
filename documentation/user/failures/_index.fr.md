---
title: Échecs et réessais
weight: 26
---

# Échecs et réessais

Une activité qui échoue, ce n'est pas un événement mais plusieurs, et savoir les distinguer est ce
qui permet de décider s'il faut compenser, alerter, ou laisser le workflow mourir. La compensation
est montrée dans [Annulation](../cancellation/#compenser).

---

## Ce que le journal enregistre pour une activité

| Événement | Sens |
|---|---|
| `ActivityScheduled` | le workflow l'a demandée |
| `ActivityTaskStarted` | une tentative a commencé, une ligne par tentative |
| `ActivityTaskFailed` | **une tentative a échoué**, qu'une autre suive ou non |
| `ActivityTaskCompleted` | journaux enregistrés avant #262 seulement : un succès n'écrit plus que `ActivityCompleted` |
| `ActivityCompleted` | résultat final : succès |
| `ActivityFailed` | résultat final : échec |
| `ActivityCancelled` | résultat final : retirée avant d'aboutir |
| `ActivityCatastrophicFailure` | l'échec lui-même n'a pas pu être journalisé sans risque |

`ActivityTaskFailed` compte : sans lui, une tentative qui échouait puis se voyait suivie d'un succès
ne laissait **aucune trace**. La première erreur disparaissait du journal.

---

## Pourquoi une activité a cessé de réessayer

`ActivityFailed` porte un `retryState` qui dit dans laquelle des quatre situations vous êtes. Elles
étaient autrefois indiscernables :

```php
use Gplanchat\Durable\Failure\ActivityRetryState;

$failed->retryState();     // ActivityRetryState
$failed->isStalled();      // vrai quand les tentatives sont épuisées
```

| État | Sens |
|---|---|
| `NonRetryableFailure` | l'exception est déclarée non réessayable, elle ne sera donc jamais retentée |
| `MaximumAttemptsReached` | toutes les tentatives autorisées ont été consommées |
| `Timeout` | une borne « planification à démarrage » ou « planification à clôture » s'est écoulée |
| `RetryPolicyNotSet` | aucune politique de réessai ne s'appliquait |
| `InProgress` | non final ; une autre tentative est attendue |

`InProgress` est la façon d'enregistrer un échec qui n'est **pas** un dénouement. Il apparaît quand
le réessai est délégué au serveur Temporal, et il ne compte délibérément pas comme un dénouement
terminal, pour que la tentative suivante ait bien lieu.

Cet état reflète le `RetryState` de Temporal : là aussi, c'est un **champ de l'échec**, pas un type
d'événement distinct.

---

## Déclarer une exception non réessayable

```php
use Gplanchat\Durable\Activity\ActivityOptions;

ActivityOptions::of(5, nonRetryableExceptions: [PaymentRefusedException::class]);
```

Une carte refusée ne s'arrangera pas à la troisième tentative. Sur le backend Temporal, cela devient
le `nonRetryableErrorTypes` de la politique de réessai : le **serveur** cesse de réessayer lui
aussi, pas seulement le worker PHP.

---

## Le décompte des tentatives

`RetryLimit::ofAttempts(3)` signifie **trois exécutions au total**, comme sur Temporal, et non
trois réessais après un premier essai.

> [!WARNING]
> Sans limite explicite, les tentatives sont **illimitées**. Une activité qui échoue
> systématiquement réessaiera indéfiniment et le workflow n'échouera jamais. Voir
> [Options](../options/#retrylimit).

---

## Quel réglage de réessai vit où (Symfony Messenger)

Durable décide ; Messenger achemine.

| Question | Qui répond | Le réglage |
|---|---|---|
| Cet échec vaut-il une nouvelle tentative ? | Durable | `nonRetryableExceptions` sur `ActivityOptions` |
| Combien de tentatives, et quel délai entre elles ? | Durable | `retryLimit`, `initialInterval`, `backoffCoefficient`, `maximumInterval` ; `max_activity_retries` les plafonne tous |
| Où va une activité en échec pour qu'un opérateur la retrouve ? | Messenger | `failure_transport` |
| Acheminement, acquittement, le transport lui-même | Messenger | le transport `durable_activities` |

Durable planifie ses propres réessais : une tentative réessayée est un nouveau message, mis en file
avec son délai. Le handler ne lève jamais d'exception pour un échec que Durable va réessayer : le
`retry_strategy` de Messenger ne s'applique donc pas aux activités, et il n'y a pas de second
compteur.

Un échec **non réessayable** est journalisé, puis levé en `UnrecoverableMessageHandlingException` :
Messenger ne le réessaie pas, et l'envoie au `failure_transport` si vous en avez configuré un.
`messenger:failed:retry` sur ce message ne relance rien. Le journal contient déjà cette tentative et
répond à sa place.

---

## Quand c'est le workflow lui-même qui échoue

Une erreur que le workflow ne traite pas produit un `WorkflowExecutionFailed`, dont le `kind` dit
d'où elle vient :

| Genre | Origine |
|---|---|
| `unhandled_activity_failure` | un échec d'activité que le workflow a laissé s'échapper |
| `unhandled_declared_activity_failure` | un échec métier déclaré qu'il a laissé s'échapper |
| `unhandled_catastrophic_activity_failure` | un échec d'activité qui n'a pas pu être journalisé |
| `unhandled_activity_superseded` | un perdant de course qu'il a attendu quand même |
| `workflow_handler_failure` | le code du workflow a lui-même levé |
| `terminated_by_parent` | un parent s'est fermé avec `ParentClosePolicy::Terminate` |

Sur le backend Temporal, ce genre voyage dans les détails d'`ApplicationFailureInfo` : relire
l'historique reconstruit donc un `WorkflowExecutionFailed` typé plutôt qu'un simple message. Le nom
de l'activité fautive survit à l'aller-retour.
