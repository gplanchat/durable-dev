---
title: Options et objets valeur
weight: 32
---

# Options et objets valeur

Les options de planification — limites de réessai, délais, files de tâches, planifications cron,
attributs de recherche — sont des **objets valeur**, pas des primitives. Chacun valide ce qu'il peut
à la construction, si bien qu'une erreur se manifeste là où vous l'avez écrite, plutôt qu'en rejet
du serveur, en valeur réécrite en silence, ou en exécution qui attend indéfiniment.

Chaque règle appliquée ici a été **éprouvée contre un serveur Temporal en marche** avant d'être
écrite. Là où le serveur est permissif, ces objets le sont en général aussi ; là où ils sont plus
stricts, le docblock dit pourquoi.

---

## `Duration`

Une longueur de temps. Remplace les champs `?float …Seconds`.

```php
use Gplanchat\Durable\Duration;

Duration::seconds(30);
Duration::milliseconds(250);
Duration::minutes(2.5);
Duration::hours(1);
Duration::zero();                       // aucune attente
Duration::infinity();                   // aucune borne — l'échéance par défaut d'await()
```

`infinity()` est une **valeur**, pas une absence. Elle se compare (`shortest()`, `isLongerThan()`),
elle voyage dans la configuration, et elle évite au code qui calcule une échéance d'écrire un cas
particulier pour « pas de borne ». Ce n'est pas une durée transmissible : `timer()` la refuse, car
un minuteur qui ne se déclenche jamais est une commande inscrite à l'historique pour un réveil qui
ne viendra pas.

Elle accepte aussi les valeurs natives et Carbon, sans dépendre de Carbon :

```php
Duration::of(new DateInterval('PT90S'));          // CarbonInterval étend DateInterval
Duration::of(CarbonInterval::minutes(5));
Duration::until($deadline);                       // Carbon implémente DateTimeInterface
Duration::until($deadline, $from);
Duration::from($anything);                        // Duration|DateInterval|DateTimeInterface|int|float
$duration->toDateInterval();
```

`of()` prend une **longueur**, `until()` prend un **instant**. Un `DateTimeInterface` ne devient une
durée qu'une fois mesuré contre un autre instant : d'où deux méthodes et non une seule.

Une durée négative est refusée, tout comme un `INF` ou un `NAN` calculé — une durée infinie se
demande par son nom. Les unités calendaires (années, mois) n'ont pas de longueur fixe et sont
résolues contre une ancre UTC fixe : préférez les jours, les heures et les minutes pour une borne.

---

## `RetryLimit` {#retrylimit}

Jusqu'où vous acceptez de réessayer une activité.

```php
use Gplanchat\Durable\Activity\RetryLimit;

RetryLimit::unlimited();        // aucune borne sur le nombre de tentatives (le défaut)
RetryLimit::ofAttempts(3);      // trois tentatives au total
RetryLimit::ofRetries(2);       // deux réessais — donc trois tentatives
RetryLimit::once();             // tout échec est définitif
```

> [!WARNING]
> **L'illimité est le défaut**, à l'image d'une `RetryPolicy` Temporal sans `maximum_attempts`. Une
> activité qui échoue systématiquement sans borner ses tentatives **ne fera pas échouer le
> workflow** — elle réessaiera indéfiniment. Seuls une exception non réessayable, un dépassement de
> délai ou une annulation l'arrêtent.
>
> Passez `RetryLimit::once()` quand vous voulez qu'un échec soit définitif.

`ofAttempts(0)` est refusé : une limite non bornée s'écrit `unlimited()`, pas zéro. `ofRetries(0)`
signifie « pas de plafond », le sens que ce réglage a toujours eu dans la configuration du bundle.

---

## `ActivityTimeouts`

Les quatre bornes d'une activité, prises ensemble — parce que chacune borne un segment différent de
sa vie :

```
planifiée ──planification-à-démarrage──▶ démarrée ──démarrage-à-clôture──▶ terminée
└────────────────── planification-à-clôture ──────────────────────────────┘
                    battement : le plus long silence toléré pendant l'exécution
```

```php
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Duration;

ActivityTimeouts::none();                              // le backend décide
ActivityTimeouts::attempt(Duration::seconds(30));      // le cas courant : borner une tentative

(new ActivityTimeouts(
    scheduleToStart: Duration::seconds(10),
    startToClose:    Duration::minutes(5),
    scheduleToClose: Duration::minutes(30),
    heartbeat:       Duration::seconds(30),
));
```

Un battement plus long que `startToClose` est refusé : la tentative se terminerait avant le premier
battement manqué, et la borne serait donc morte.

Temporal exige une borne de clôture. Quand aucune n'est posée, le pont en fournit une par défaut —
ce repli s'appelle `executionBoundOr()` plutôt que d'être caché dans la construction de la commande.

---

## Assembler les options d'activité

```php
use Gplanchat\Durable\Activity\ActivityOptions;

// 3 tentatives, 30 s chacune, 1 s avant le premier réessai.
$options = ActivityOptions::of(3, 30, 1, [PaymentRefusedException::class], 'payments');
```

`of()` est le constructeur écrit dans l'ordre où l'on pense : combien de tentatives, et combien de
temps chacune peut prendre. Il accepte les équivalents scalaires — un **entier** est un nombre de
tentatives, une **durée** nue est la borne `startToClose` d'une tentative, un **flottant** est un
nombre de secondes. Rien n'est magique : `of(0)` est refusé plutôt que lu comme « illimité ». La
forme longue reste disponible et strictement équivalente, pour quand vous voulez nommer chaque
intention :

```php
use Gplanchat\Durable\Activity\{ActivityOptions, ActivityTimeouts, RetryLimit};
use Gplanchat\Durable\{Duration, TaskQueue};

$options = new ActivityOptions(
    RetryLimit::ofAttempts(3),
    initialInterval: Duration::seconds(1),
    nonRetryableExceptions: [PaymentRefusedException::class],
    taskQueue: TaskQueue::named('payments'),
    timeouts: ActivityTimeouts::attempt(Duration::seconds(30)),
);

// Les options sont portées par le stub : tous ses appels s'en servent.
$orders = $this->environment->activityStub(OrderActivities::class, $options);

$result = $this->environment->await($orders->charge($orderId));
```

L'intervalle de réessai croît selon `backoffCoefficient` et se plafonne. Sans plafond explicite,
c'est le défaut de Temporal qui s'applique : **100 × l'intervalle initial**. Ce plafond compte dès
lors que les tentatives sont illimitées — sans lui, un recul exponentiel diverge.

---

## `WorkflowTimeouts`

Les trois bornes du workflow, qui s'emboîtent :

```
exécution ─┬─ run 1 ─┬─ run 2 (continue-as-new, réessai) ─ …
           │         └─ tâche : un aller-retour de décision du worker
           └────────────── exécution : toute la chaîne
```

```php
use Gplanchat\Durable\{Duration, WorkflowTimeouts};

WorkflowTimeouts::none();
WorkflowTimeouts::run(Duration::minutes(10));

new WorkflowTimeouts(
    execution: Duration::hours(1),
    run:       Duration::minutes(10),
    task:      Duration::seconds(10),
);
```

Une borne de run plus longue que la borne d'exécution est **refusée**. Le serveur, lui, ne la refuse
pas : il rabaisse silencieusement la borne de run à la borne d'exécution, si bien que la
configuration que vous avez écrite n'est pas celle qui s'applique. Autant l'apprendre.

`ContinueAsNewOptions` refuse purement et simplement une borne d'exécution : le nouveau run
appartient à l'exécution courante et en hérite. Employez `withoutExecutionBound()` pour y réutiliser
un `WorkflowTimeouts`.

---

## `TaskQueue` et `WorkflowNamespace`

```php
use Gplanchat\Durable\{TaskQueue, WorkflowNamespace};

TaskQueue::named('payments-activities');
WorkflowNamespace::named('billing');
```

Les deux refusent un nom vide, des espaces en bordure et des caractères de contrôle. Le serveur
accepte les trois, mais ils ne sont jamais intentionnels — et pour une file de tâches la conséquence
est silencieuse : le travail est mis en file sous un nom que personne n'interroge, et l'exécution
attend, sans rien dans les journaux.

> [!NOTE]
> Ni l'un ni l'autre n'attrape une faute de frappe qui reste un nom valide —
> `payments-activites` pour `payments-activities`. Une file de tâches échoue en silence ; un espace
> de noms échoue bruyamment, en `NOT_FOUND`. Attraper la première demanderait un registre des files
> réellement servies.

La comparaison d'espaces de noms est **sensible à la casse**, comme sur le serveur : `Billing` et
`billing` sont deux espaces de noms différents.

---

## `CronSchedule`

Une récurrence, validée à la construction — sans quoi une faute de frappe n'apparaîtrait qu'au refus
du premier démarrage par le serveur.

```php
use Gplanchat\Durable\{CronSchedule, Duration};

CronSchedule::parse('0 9 * * 1-5');
CronSchedule::daily();                              // et aussi hourly, weekly, monthly, yearly
CronSchedule::every(Duration::minutes(90));         // @every 1h30m
CronSchedule::dailyAt(9, 30);                       // 30 9 * * *
CronSchedule::dailyAt(9)->inTimeZone('Europe/Paris');
```

> [!WARNING]
> Sans fuseau horaire, le serveur lit l'expression en **UTC** — rarement ce que « tous les jours à
> 9 h » est censé vouloir dire. `inTimeZone()` émet le préfixe `CRON_TZ=` que le serveur attend.

La validation reproduit celle du serveur, expression par expression : nombre de champs, caractères,
plages, et **atteignabilité** — `0 0 31 4 *` est refusé parce qu'avril compte trente jours. Le jour
de la semaine va de 0 à 6, donc `7` pour dimanche est refusé. `?` est accepté partout comme synonyme
de `*`.

Les deux erreurs les plus probables sont nommées dans le message : une expression à six champs (un
cron Quartz copié d'ailleurs) et une planification sans aucune occurrence.

Voir [Workflows récurrents](#recurring-workflows) plus bas pour en démarrer un.

---

## `SearchAttributes`

Ce par quoi une exécution peut être retrouvée.

```php
use Gplanchat\Durable\{SearchAttributes, WorkflowStartOptions};

$attributes = SearchAttributes::none()
    ->keyword('OrderId', 'ORD-4242')
    ->int('Amount', 4242)
    ->bool('Priority', true)
    ->double('Ratio', 0.75)
    ->text('Note', 'gift wrapping')
    ->datetime('DueAt', new DateTimeImmutable('2026-01-01'))
    ->keywordList('Tags', ['gift', 'express']);

$client->startAsync('CheckoutWorkflow', $input, $executionId, new WorkflowStartOptions(
    searchAttributes: $attributes,
));
```

L'objet est immuable : chaque appel renvoie une nouvelle instance.

Deux des trois règles du serveur sont vérifiées localement :

- **la valeur doit correspondre au type** — un `Int` à qui l'on donne une chaîne est refusé avant
  l'aller-retour ;
- **seize attributs système sont en lecture seule** (`RunId`, `WorkflowId`, `TaskQueue`,
  `StartTime`, …). `BuildIds`, `BinaryChecksums` et `TemporalChangeVersion` n'en font *pas* partie
  et peuvent être écrits.

La troisième ne peut pas être vérifiée ici : **l'attribut doit être enregistré dans l'espace de
noms**. Cela supposerait de lire le registre de l'espace de noms. Le serveur répond
`has no mapping defined for search attribute`.

```bash
temporal operator search-attribute create --name OrderId --type Keyword
temporal operator search-attribute create --name Amount  --type Int
```

---

## Workflows récurrents {#recurring-workflows}

```php
use Gplanchat\Durable\{CronSchedule, WorkflowStartOptions};

$client->startCron('NightlyReconciliation', $input, $executionId, CronSchedule::dailyAt(2));

// ou par l'objet d'options, aux côtés des délais et des attributs de recherche
$client->startAsync('NightlyReconciliation', $input, $executionId, new WorkflowStartOptions(
    cronSchedule: CronSchedule::dailyAt(2)->inTimeZone('Europe/Paris'),
));
```

Un cron Temporal n'est **pas un ordonnanceur externe** : c'est la même exécution logique, relancée
par le serveur avec un historique neuf à chaque échéance. Le run suivant ne démarre pas tant que le
précédent n'est pas terminé — une occurrence manquée est **sautée, pas rattrapée**.

Les workflows enfants acceptent la même planification par `ChildWorkflowOptions`.

> [!NOTE]
> Le cron est une capacité de Temporal. Le backend en mémoire n'a pas d'ordonnanceur et ne le prend
> pas en charge.

---

## Migrer depuis l'API précédente

Les arguments nommés ont changé en même temps que les objets valeur. Un appel non migré échoue
immédiatement et bruyamment, jamais en silence.

| Avant | Maintenant |
|---|---|
| `maxAttempts: 3` | `RetryLimit::ofAttempts(3)` en premier argument |
| `maxAttempts: 1` | `RetryLimit::once()` |
| `maxAttempts: 0` | `RetryLimit::unlimited()` — et c'est le défaut |
| `->withMaxAttempts(3)` | `->withRetryLimit(RetryLimit::ofAttempts(3))` |
| `initialIntervalSeconds: 1.0` | `initialInterval: Duration::seconds(1)` |
| `maximumIntervalSeconds: 60.0` | `maximumInterval: Duration::seconds(60)` |
| `startToCloseTimeoutSeconds: 30.0` | `timeouts: ActivityTimeouts::attempt(Duration::seconds(30))` |
| `scheduleToStartTimeoutSeconds`, `scheduleToCloseTimeoutSeconds`, `heartbeatTimeoutSeconds` | arguments nommés d'`ActivityTimeouts` |
| `workflowRunTimeoutSeconds: 600.0` | `timeouts: WorkflowTimeouts::run(Duration::minutes(10))` |
| `workflowExecutionTimeoutSeconds`, `workflowTaskTimeoutSeconds` | arguments nommés de `WorkflowTimeouts` |
| `taskQueue: 'payments'` | `taskQueue: TaskQueue::named('payments')` |
| `namespace: 'billing'` | `namespace: WorkflowNamespace::named('billing')` |
| `cronSchedule: '0 9 * * *'` | `cronSchedule: CronSchedule::parse('0 9 * * *')` |
| `searchAttributes: ['OrderId' => 'x']` | `SearchAttributes::none()->keyword('OrderId', 'x')` |

> [!CAUTION]
> **Changement de comportement, pas seulement de signatures.** `maxAttempts: 0` voulait dire *aucun
> réessai* et veut maintenant dire *illimité*, comme sur Temporal. Une activité qui échoue
> systématiquement sans borner ses tentatives ne fait plus échouer le workflow. Repassez sur toute
> activité qui s'appuyait sur l'ancien défaut et posez-y `RetryLimit::once()` là où un échec doit
> être définitif.

Douze méthodes `with*()` d'`ActivityOptions` que personne n'appelait ont été retirées.
`withRetryLimit()` et `withTimeouts()` restent.

Les attributs de recherche avaient un second problème, plus discret : ils étaient journalisés et
**jamais envoyés au serveur**. Ils lui parviennent désormais, si bien qu'un attribut qui était
silencieusement perdu peut maintenant être rejeté comme non enregistré. Enregistrez-le, ou
retirez-le.
