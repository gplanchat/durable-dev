---
title: Options et objets valeur
weight: 32
---

# Options et objets valeur

Les options de planification (limites de réessai, délais, files de tâches, planifications cron,
attributs de recherche) sont des **objets valeur** plutôt que des primitives. Chacun valide ce qu'il
peut à la construction. Une erreur apparaît donc à la ligne où vous l'avez écrite, au lieu de
surgir plus tard en rejet du serveur, en valeur réécrite en silence, ou en exécution (un
déroulement durable d'un workflow ; voir le [glossaire](../glossary/)) qui attend indéfiniment.

Chaque règle appliquée ici a été **éprouvée contre un serveur Temporal en marche** avant d'être
écrite. Là où le serveur est permissif, ces objets le sont en général aussi ; là où ils sont plus
stricts, le docblock en donne la raison.

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
Duration::infinity();                   // aucune borne : l'échéance par défaut d'await()
```

`infinity()` est une **valeur** ordinaire. Elle se compare (`shortest()`, `isLongerThan()`),
elle voyage dans la configuration, et elle évite au code qui calcule une échéance un cas
particulier pour « pas de borne ». Elle ne peut pas être transmise au serveur : `timer()` la
rejette, car un minuteur qui ne se déclenche jamais est une commande inscrite à l'historique pour
un réveil qui n'arrive jamais.

Elle accepte aussi les valeurs natives et Carbon, sans dépendre de Carbon :

```php
Duration::of(new DateInterval('PT90S'));          // CarbonInterval étend DateInterval
Duration::of(CarbonInterval::minutes(5));
Duration::until($deadline);                       // Carbon implémente DateTimeInterface
Duration::until($deadline, from: $from);
Duration::from($anything);                        // Duration|DateInterval|DateTimeInterface|int|float
$duration->toDateInterval();
```

`of()` prend une **longueur**, `until()` prend un **instant**. Un `DateTimeInterface` ne devient une
durée qu'une fois mesuré contre un autre instant, d'où deux méthodes distinctes.

Une durée négative est rejetée, tout comme un `INF` ou un `NAN` calculé : une durée infinie se
crée par son nom, avec `infinity()`. Les unités calendaires (années, mois) n'ont pas de longueur fixe et sont
résolues contre une ancre UTC fixe : préférez les jours, les heures et les minutes pour une borne.

---

## `RetryLimit` {#retrylimit}

Combien de fois une activité peut s'exécuter. Une activité est une unité d'effet de bord dans un
workflow : un appel HTTP, une écriture en base, un e-mail ; voir le [glossaire](../glossary/).

```php
use Gplanchat\Durable\Activity\RetryLimit;

RetryLimit::unlimited();        // aucune borne sur le nombre de tentatives (le défaut)
RetryLimit::ofAttempts(3);      // trois tentatives au total
RetryLimit::ofRetries(2);       // deux réessais, donc trois tentatives
RetryLimit::once();             // tout échec est définitif
```

> [!WARNING]
> **L'illimité est le défaut**, à l'image d'une `RetryPolicy` Temporal sans `maximum_attempts`. Une
> activité qui échoue systématiquement sans borner ses tentatives **ne fait pas échouer le
> workflow** ; elle réessaie indéfiniment. Seuls une exception non réessayable, un dépassement de
> délai ou une annulation l'arrêtent.
>
> Passez `RetryLimit::once()` quand vous voulez qu'un échec soit définitif.

`ofAttempts(0)` est rejeté ; une limite non bornée s'écrit `unlimited()`. `ofRetries(0)` signifie
« pas de plafond », le sens que ce réglage a toujours eu dans la configuration du bundle.

---

## `ActivityTimeouts`

Les quatre bornes d'une activité, prises ensemble, parce que chacune borne un segment différent de
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

// Chaque borne a son with…() qui rend une copie où seule celle-ci change ; null la retire.
ActivityTimeouts::attempt(Duration::seconds(30))
    ->withScheduleToStart(Duration::seconds(10))
    ->withHeartbeat(Duration::seconds(5));
```

Un battement plus long que `startToClose` est rejeté : la tentative se terminerait avant le premier
battement manqué, et la borne de battement ne s'appliquerait donc jamais.

La borne de battement n'existe que sur Temporal. Un backend à journal (InMemory, DBAL, Illuminate,
Magento Database) lève `UnsupportedByBackendException` quand vous planifiez une activité avec une borne de battement.

Hors Temporal, `startToClose` est vérifié quand la tentative se termine ; rien ne l'impose pendant
qu'elle tourne. Une tentative qui a dépassé échoue sur un délai dépassé, son résultat est écarté,
et la politique de reprise détermine la suite. Rien n'interrompt une tentative qui ne rend jamais
la main. Arrêter un worker bloqué revient à ce qui supervise le processus.
`messenger:consume --time-limit` ne vérifie qu'entre deux messages, il ne peut donc pas l'arrêter.

`scheduleToClose` borne l'activité entière, reprises comprises. Sur Temporal comme sur les backends
à journal, une tentative échouée dont le délai de reprise dépasserait cette borne n'est pas
reprise : l'activité échoue avec l'échec de cette tentative et l'état de reprise `Timeout`. Sur les
backends à journal, la borne est aussi vérifiée quand un worker prend le message, et échoue avec
« Activity schedule-to-close timeout exceeded. » `scheduleToStart` s'applique à un message qui
attend dans la file. Sur Temporal, il s'applique à chaque tentative. Sur les backends à journal, il
ne s'applique qu'à la première, et une reprise qui attend son délai n'est bornée que par
`scheduleToClose`.

Temporal exige une borne de clôture. Quand aucune n'est posée, le pont en fournit une par défaut.
Ce repli porte son propre nom, `executionBoundOr()`.

---

## Assembler les options d'activité

```php
use Gplanchat\Durable\Activity\ActivityOptions;

// 3 tentatives, 30 s chacune, 1 s avant le premier réessai.
$options = ActivityOptions::of(3, 30, 1, [PaymentRefusedException::class], 'payments');
```

`of()` prend ses arguments dans l'ordre où vous raisonnez : combien de tentatives, et combien de
temps chacune peut prendre. Il accepte des équivalents scalaires : un **entier** est un nombre de
tentatives, une **durée** nue est la borne `startToClose` d'une tentative, et un **flottant** est
un nombre de secondes. `of(0)` est rejeté ; il n'est pas lu comme « illimité ». La forme longue
reste disponible et strictement équivalente, pour quand vous voulez nommer chaque intention :

```php
use Gplanchat\Durable\Activity\{ActivityOptions, ActivityTimeouts, RetryLimit};
use Gplanchat\Durable\{Duration, TaskQueue};

$options = new ActivityOptions(
    RetryLimit::ofAttempts(3),
    initialInterval: Duration::seconds(1),
    nonRetryableExceptions: [PaymentRefusedException::class],
    taskQueue: TaskQueue::named('payments'), // Temporal uniquement ; sur un backend à journal, cette option lève une exception
    timeouts: ActivityTimeouts::attempt(Duration::seconds(30)),
);

// Les options sont portées par le stub : tous ses appels s'en servent.
$orders = $this->environment->activityStub(OrderActivities::class, $options);

$result = $this->environment->await($orders->charge($orderId));
```

L'intervalle de réessai croît selon `backoffCoefficient` et se plafonne. Sans plafond explicite,
le défaut de Temporal s'applique : **100 × l'intervalle initial**. Avec des tentatives illimitées,
ce plafond empêche un recul exponentiel de diverger.

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

// Les mêmes copies with…() : withExecution(), withRun(), withTask() ; null retire la borne.
WorkflowTimeouts::run(Duration::minutes(10))->withTask(Duration::seconds(10));
```

Une borne de run plus longue que la borne d'exécution est **rejetée** à la construction. Le
serveur l'accepte et rabaisse silencieusement la borne de run à la borne d'exécution, si bien que la
configuration que vous avez écrite n'est pas celle qui s'applique.

`ContinueAsNewOptions` rejette toute borne d'exécution : le nouveau run
appartient à l'exécution courante et en hérite. Employez `withoutExecutionBound()` pour y réutiliser
un `WorkflowTimeouts`. Ses propres copies sont `withTimeouts()` et `withTaskQueue()`, qui fait
passer le run suivant sur une autre file de tâches.

---

## `TaskQueue` et `WorkflowNamespace`

```php
use Gplanchat\Durable\{TaskQueue, WorkflowNamespace};

TaskQueue::named('payments-activities');
WorkflowNamespace::named('billing');
```

Les deux rejettent un nom vide, des espaces en bordure et des caractères de contrôle. Le serveur
accepte les trois, mais ils ne sont jamais intentionnels. Pour une file de tâches, la conséquence
est silencieuse : le travail est mis en file sous un nom qu'aucun worker n'interroge, et
l'exécution attend, sans rien dans les logs.

> [!NOTE]
> Ni l'un ni l'autre n'attrape une faute de frappe qui reste un nom valide, tel que
> `payments-activites` pour `payments-activities`. Une file de tâches échoue en silence ; un espace
> de noms échoue bruyamment, en `NOT_FOUND`. Attraper la première demanderait un registre des files
> réellement servies.

La comparaison d'espaces de noms est **sensible à la casse**, comme sur le serveur : `Billing` et
`billing` sont deux espaces de noms différents.

---

## `CronSchedule`

Une récurrence, validée à la construction. Sans cette validation, une faute de frappe n'apparaît
que lorsque le serveur rejette le premier démarrage.

```php
use Gplanchat\Durable\{CronSchedule, Duration};

CronSchedule::parse('0 9 * * 1-5');
CronSchedule::daily();                              // et aussi hourly, weekly, monthly, yearly
CronSchedule::every(Duration::minutes(90));         // @every 1h30m
CronSchedule::dailyAt(9, 30);                       // 30 9 * * *
CronSchedule::dailyAt(9)->inTimeZone('Europe/Paris');
```

> [!WARNING]
> Sans fuseau horaire, le serveur lit l'expression en **UTC**, ce qui correspond rarement au sens
> de « tous les jours à 9 h ». `inTimeZone()` émet le préfixe `CRON_TZ=` que lit le serveur.

La validation reproduit celle du serveur, expression par expression. Elle vérifie le nombre de
champs, les caractères, les plages et l'**atteignabilité** : `0 0 31 4 *` est rejeté parce
qu'avril compte trente jours. Le jour de la semaine va de 0 à 6, donc `7` pour dimanche est rejeté. `?` est accepté partout comme synonyme
de `*`.

Les deux erreurs les plus probables sont nommées dans le message : une expression à six champs (un
cron Quartz copié d'ailleurs) et une planification sans aucune occurrence.

Voir [Workflows récurrents](#recurring-workflows) plus bas pour en démarrer un.

---

## `SearchAttributes`

Ce par quoi une exécution peut être retrouvée.

```php
use Gplanchat\Durable\{ExecutionId, SearchAttributes, WorkflowStartOptions};

$attributes = SearchAttributes::none()
    ->keyword('OrderId', 'ORD-4242')
    ->int('Amount', 4242)
    ->bool('Priority', true)
    ->double('Ratio', 0.75)
    ->text('Note', 'gift wrapping')
    ->datetime('DueAt', new DateTimeImmutable('2026-01-01'))
    ->keywordList('Tags', ['gift', 'express']);

$client->startAsync('CheckoutWorkflow', $input, ExecutionId::fromString($executionId), new WorkflowStartOptions(
    searchAttributes: $attributes,
));
```

> [!NOTE]
> `$client` est le `WorkflowClientInterface` de Temporal, et `startAsync()` n'existe que sur
> Temporal, comme les options de démarrage qu'il prend. `WorkflowClientInterface::startAsync()` et
> `startSync()` déclarent l'argument `?WorkflowStartOptions $options` : le code typé contre l'interface
> peut le passer. Sur tous les backends, une exécution démarre
> par `WorkflowResumeDispatcher::dispatchNewWorkflowRun()` ([Premiers pas](../getting-started/#4--le-déclencher-depuis-un-contrôleur-ou-un-service)),
> qui ne prend pas d'options de démarrage.

L'objet est immuable : chaque appel renvoie une nouvelle instance.

Deux des trois règles du serveur sont vérifiées localement :

- **la valeur doit correspondre au type** : un `Int` qui reçoit une chaîne est rejeté avant
  l'aller-retour ;
- **seize attributs système sont en lecture seule** (`RunId`, `WorkflowId`, `TaskQueue`,
  `StartTime`, …). `BuildIds`, `BinaryChecksums` et `TemporalChangeVersion` n'en font *pas* partie
  et peuvent être écrits.

La troisième ne peut pas être vérifiée localement : **l'attribut doit être enregistré dans l'espace
de noms**, et le vérifier supposerait de lire le registre de l'espace de noms. Le serveur répond
`has no mapping defined for search attribute`.

```bash
temporal operator search-attribute create --name OrderId --type Keyword
temporal operator search-attribute create --name Amount  --type Int
```

---

## Workflows récurrents {#recurring-workflows}

```php
use Gplanchat\Durable\{CronSchedule, ExecutionId, WorkflowStartOptions};

$client->startCron('NightlyReconciliation', $input, $executionId, CronSchedule::dailyAt(2));

// ou par l'objet d'options, aux côtés des délais et des attributs de recherche
$client->startAsync('NightlyReconciliation', $input, ExecutionId::fromString($executionId), new WorkflowStartOptions(
    cronSchedule: CronSchedule::dailyAt(2)->inTimeZone('Europe/Paris'),
));
```

Un cron Temporal est la même exécution logique, relancée par le serveur avec un historique neuf à
chaque échéance ; **aucun ordonnanceur externe** n'intervient. Le run suivant ne démarre pas tant
que le précédent n'est pas terminé, et une occurrence manquée est **sautée**, jamais rattrapée.

Les workflows enfants acceptent la même planification par `ChildWorkflowOptions`.

> [!NOTE]
> Le cron est une capacité de Temporal. Les backends à journal (mémoire, DBAL, Illuminate, Magento Database)
> n'ont pas d'ordonnanceur : le `cronSchedule`, le `namespace` ou le `taskQueue` d'un workflow enfant y échoue
> avec `UnsupportedByBackendException`.

---

## Mémo, résumé et détails d'un enfant {#child-memo-summary-and-details}

`ChildWorkflowOptions` prend un mémo, un résumé d'une ligne et des détails plus longs pour l'enfant.

```php
use Gplanchat\Durable\ChildWorkflowOptions;

$shipment = $env->childWorkflowStub(ShipmentWorkflow::class, new ChildWorkflowOptions(
    memo: ['orderId' => 'ORD-4242'],
    staticSummary: 'Ship order ORD-4242',
    staticDetails: 'Two parcels, carrier chosen at dispatch',
));
```

Les backends SQL et en mémoire enregistrent les trois dans le journal du parent, sur l'événement qui
planifie l'enfant. Sur Temporal, le mémo devient celui de l'enfant, et le résumé et les détails
deviennent les métadonnées utilisateur de la commande de démarrage, que l'interface de Temporal
affiche sur l'enfant.

> [!NOTE]
> Le résumé et les détails demandent Temporal Server 1.25 ou plus récent. Un serveur plus ancien
> les ignore sans erreur, et l'enfant démarre sans eux. Le mémo passe sur tous les serveurs pris en
> charge.

Durable réserve les clés de mémo `durableExecutionId` et `durableWaitingOn`, et les écrit lui-même
dans le mémo d'un enfant sur Temporal. Sur tous les backends, `new ChildWorkflowOptions()` lève
`UnsupportedByBackendException` quand le mémo utilise l'une d'elles. Une exécution qui construit
de telles options échoue à cette ligne, y compris pendant le rejeu (le code du workflow qui tourne
à nouveau depuis sa première ligne pour reprendre ; voir le [glossaire](../glossary/)). Le rejeu
compare le type et l'entrée d'un enfant avec le journal, pas son mémo : une exécution en cours
reprend dès que le code utilise une autre clé.

---

## Migrer depuis l'API précédente

Les arguments nommés ont changé en même temps que les objets valeur. Un appel non migré échoue
immédiatement, avec une erreur.

| Avant | Maintenant |
|---|---|
| `maxAttempts: 3` | `RetryLimit::ofAttempts(3)` en premier argument |
| `maxAttempts: 1` | `RetryLimit::once()` |
| `maxAttempts: 0` | `RetryLimit::unlimited()`, et c'est le défaut |
| `->withMaxAttempts(3)` | `->withRetryLimit(RetryLimit::ofAttempts(3))` |
| `initialIntervalSeconds: 1.0` | `initialInterval: Duration::seconds(1)` |
| `maximumIntervalSeconds: 60.0` | `maximumInterval: Duration::seconds(60)` |
| `startToCloseTimeoutSeconds: 30.0` | `timeouts: ActivityTimeouts::attempt(Duration::seconds(30))` |
| `scheduleToStartTimeoutSeconds`, `scheduleToCloseTimeoutSeconds`, `heartbeatTimeoutSeconds` | arguments nommés d'`ActivityTimeouts` |
| `workflowRunTimeoutSeconds: 600.0` | `timeouts: WorkflowTimeouts::run(Duration::minutes(10))` |
| `workflowExecutionTimeoutSeconds`, `workflowTaskTimeoutSeconds` | arguments nommés de `WorkflowTimeouts` |
| `taskQueue: 'payments'` | `taskQueue: TaskQueue::named('payments')` (sur les options d'activité : Temporal uniquement ; sur un backend à journal, cette option lève une exception) |
| `namespace: 'billing'` | `namespace: WorkflowNamespace::named('billing')` |
| `cronSchedule: '0 9 * * *'` | `cronSchedule: CronSchedule::parse('0 9 * * *')` |
| `searchAttributes: ['OrderId' => 'x']` | `SearchAttributes::none()->keyword('OrderId', 'x')` |

> [!CAUTION]
> **Le comportement change en plus des signatures.** `maxAttempts: 0` voulait dire *aucun
> réessai* et veut maintenant dire *illimité*, comme sur Temporal. Une activité qui échoue
> systématiquement sans borner ses tentatives ne fait plus échouer le workflow. Repassez sur toute
> activité qui s'appuyait sur l'ancien défaut et posez-y `RetryLimit::once()` là où un échec doit
> être définitif.

Douze méthodes `with*()` d'`ActivityOptions` que personne n'appelait ont été retirées.
`withRetryLimit()` et `withTimeouts()` restent.

Les attributs de recherche avaient un second problème, moins visible : ils étaient écrits dans le
journal (l'enregistrement de tout ce qu'une exécution a décidé et reçu) et **jamais envoyés au
serveur**. Ils lui parviennent désormais, si bien qu'un attribut qui était
silencieusement perdu peut maintenant être rejeté comme non enregistré. Enregistrez-le, ou
retirez-le.
