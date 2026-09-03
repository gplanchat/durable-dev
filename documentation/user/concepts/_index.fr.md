---
title: Concepts
weight: 20
---

# Concepts

Cette page pose le vocabulaire et le modèle mental de Durable. À lire avant de se lancer dans les guides pratiques.

---

## L'exécution durable

L'**exécution durable**, c'est un processus long — quelques secondes, quelques heures ou quelques jours — qui survit aux redémarrages, aux plantages et aux déploiements. Le moteur enregistre chaque décision (résultat d'activité, minuteur échu, signal reçu) dans un **historique**, puis **rejoue** cet historique pour restaurer l'état exact où le programme se trouvait.

Vu du développeur, cela ressemble à du PHP séquentiel ordinaire : on `await` une activité, on reçoit le résultat, on continue. La tolérance aux pannes est prise en charge sans qu'on ait à s'en occuper.

---

## Workflow

Un **workflow** est de la pure logique d'orchestration. Il :

- planifie des **activités** (le travail qui produit des effets de bord) et attend leurs résultats ;
- pose des **minuteurs** (attendre une durée, ou attendre une date) ;
- réagit aux **signaux** (messages externes à sens unique) et aux **mises à jour** (messages avec réponse) ;
- expose des **requêtes** en lecture seule pour inspecter son état sans le modifier ;
- lance des **workflows enfants** pour les sous-processus.

Une fonction de workflow doit être **déterministe** : à historique identique, la ré-exécuter doit produire la même suite de commandes. C'est ce qui rend le rejeu possible.

**Ce qui n'a rien à faire dans un workflow :**
- appels HTTP, requêtes en base, nombres aléatoires, horodatages — tout cela est non déterministe ;
- accès au système de fichiers, lecture de variables d'environnement ;
- toute E/S qui donnerait un résultat différent au rejeu.

Tout cela appartient aux **activités**.

---

## Activité

Une **activité** est l'unité de travail non déterministe, celle qui peut échouer. Les activités :

- font des E/S : appels HTTP, écritures en base, envois de courriels, etc. ;
- utilisent l'injection de dépendances (dépôts, clients HTTP, journaux) ;
- sont **réessayées** automatiquement en cas d'échec, selon leurs `ActivityOptions` ;
- voient leur résultat enregistré une fois pour toutes ; au rejeu, ce résultat est repris de l'historique sans que l'activité soit ré-exécutée.

Un workflow parle aux activités à travers un **`ActivityInvoker`** (le stub typé qu'on obtient par `WorkflowEnvironment::activityStub()`). Appeler une méthode du stub renvoie un **`Awaitable`** ; `await()` suspend le workflow jusqu'à ce que l'activité se termine.

---

## Historique d'événements et rejeu

Le moteur consigne chaque événement significatif dans un **historique** (aussi appelé journal) :

```
ExecutionStarted
  └─ ActivityScheduled(name: "charge-order", attempt: 1)
       └─ ActivityCompleted(name: "charge-order", result: "ok")
            └─ ExecutionCompleted(result: "done")
```

Quand le processus du workflow redémarre — ou quand Temporal planifie une nouvelle tâche de workflow — le moteur **rejoue** la fonction du workflow contre cet historique :

```
Rejeu, étape 1 : await activity("charge-order")
  → l'historique porte ActivityCompleted pour cette étape → renvoie « ok » immédiatement (aucun appel HTTP réel)
Rejeu, étape 2 : return "done"
  → l'historique porte ExecutionCompleted → le workflow est terminé
```

Parce que le résultat est dans l'historique, l'implémentation de l'activité **n'est pas rappelée** pendant le rejeu. La fonction reprend simplement là où elle s'était arrêtée.

### Les types d'événements

| Événement | Quand il est enregistré |
|-----------|-------------------------|
| `ExecutionStarted` | l'orchestrateur a accepté le workflow |
| `ActivityScheduled` | le workflow a planifié une activité |
| `ActivityCompleted` | l'activité a rendu un résultat |
| `ActivityFailed` | l'activité a levé une exception non rattrapée |
| `TimerStarted` | le workflow a posé un minuteur |
| `TimerFired` | le minuteur est échu |
| `SignalReceived` | un signal externe a été remis au workflow |
| `UpdateAccepted` | une mise à jour transactionnelle a été acceptée |
| `ChildWorkflowStarted` | un workflow enfant a été lancé |
| `ChildWorkflowCompleted` | un workflow enfant s'est terminé |
| `ExecutionCompleted` | le workflow a rendu un résultat |
| `WorkflowExecutionFailed` | le workflow a levé une exception non rattrapée |

---

## `await` et les awaitables

`WorkflowEnvironment::await()` est la primitive unique pour suspendre le workflow. Elle prend un **`Awaitable`** — un jeton léger qui tient la place d'un résultat futur — et bloque, conceptuellement, jusqu'à ce que ce résultat soit disponible.

Sous le capot, Durable s'appuie sur les **fibres** de PHP pour suspendre l'exécution sans bloquer le fil d'exécution système. La fibre reprend quand l'orchestrateur livre le résultat attendu, dans une tâche de workflow ultérieure.

```
La fibre s'exécute → atteint await(activité) → rien dans l'historique → la fibre se suspend
                   ↓
         Temporal planifie une nouvelle tâche avec ActivityCompleted
                   ↓
         La fibre reprend depuis await → reçoit le résultat → continue
```

Les awaitables se composent :

```php
// En séquence
$a = $env->await($activities->stepA());
$b = $env->await($activities->stepB($a));

// En parallèle (les deux démarrent d'un coup)
[$a, $b] = $env->await($env->all(
    $activities->stepA(),
    $activities->stepB(),
));

// À la course (le premier gagne)
$winner = $env->await($env->any(
    $activities->fastPath(),
    $activities->slowPath(),
));

// Au quorum (assez, c'est assez)
$quotes = $env->await($env->some(3, ...$providers));
```

---

## Signaux, requêtes et mises à jour

### Le signal

Un **signal** est un message externe à sens unique, remis à un workflow en cours. Un signal n'a pas de valeur de retour.

Le workflow **déclare un gestionnaire** pour les signaux qu'il accepte, et le moteur l'appelle quand il en arrive un. Le gestionnaire modifie l'état du workflow ; le corps du workflow repart quand cet état satisfait une **condition** :

```
requête HTTP → signal(« approve ») → le gestionnaire s'exécute → la condition tient → le workflow repart
```

```php
enum OrderSignal: string
{
    case Approve = 'approve';
    case Cancel  = 'cancel';
}

#[AsWorkflow('Order')]
final class OrderWorkflow
{
    /** @var list<array<string, mixed>> */
    private array $approvals = [];

    public function __construct(private readonly WorkflowEnvironment $environment) {}

    #[AsSignalMethod(OrderSignal::Approve)]
    public function onApprove(array $payload): void
    {
        $this->approvals[] = $payload;
    }

    #[AsWorkflowMethod]
    public function run(): string
    {
        $this->environment->await(fn(): bool => [] !== $this->approvals);

        return 'approved by ' . ($this->approvals[0]['by'] ?? '?');
    }
}
```

Un workflow écrit comme un appelable enregistre le même gestionnaire de façon impérative, exactement comme il le ferait d'un gestionnaire de requête :

```php
$env->onSignal(OrderSignal::Approve, function (array $payload) use (&$approvals): void {
    $approvals[] = $payload;
});
$env->await(function () use (&$approvals): bool { return [] !== $approvals; });
```

Les signaux servent aux circuits d'approbation, aux pauses et reprises, aux déclenchements externes.

**Nommez les signaux par une énumération adossée à une chaîne**, pas par un littéral. Les deux bouts prennent un `BackedEnum` — le gestionnaire qui consomme le signal comme le client qui l'envoie — de sorte qu'une énumération recense toute la surface de signaux d'un workflow, et qu'une faute de frappe devient une erreur de type plutôt qu'une attente qui ne se résout jamais (voir **DUR034**) :

```php
$client->signal($workflowId, OrderSignal::Approve, ['by' => 'alice']);
```

Une chaîne nue reste acceptée, et doit l'être : un signal peut arriver de `curl`, de la ligne de commande Temporal, ou d'un service écrit dans un autre langage. L'énumération type l'intérieur ; elle ne peut pas typer cette frontière.

> [!IMPORTANT]
> **Migrer depuis `waitSignal()`.** La méthode a disparu. Elle lisait l'historique directement, d'où
> son besoin d'un emplacement positionnel, d'un compteur par nom, et d'une règle pour l'attente qui
> renonce — toute une mécanique qu'un gestionnaire plus une condition remplacent purement et
> simplement (voir **DUR035**).
>
> ```php
> // Avant
> $approval = $env->waitSignal(OrderSignal::Approve, Duration::hours(1));
>
> // Après
> $env->onSignal(OrderSignal::Approve, fn(array $p) => $this->approvals[] = $p);
> $env->await(fn(): bool => [] !== $this->approvals, Duration::hours(1));
> $approval = array_shift($this->approvals);
> ```
>
> Ce que vous gardez des livraisons est désormais de l'état de workflow : un workflow qui attend
> trois fois le même signal conserve trois entrées et les consomme à son rythme. Ce qui est arrivé
> alors que personne n'attendait est toujours là quand l'attente suivante se présente.

### La requête

Une **requête** est une lecture synchrone de l'état du workflow. Le workflow expose une `#[AsQueryMethod]` qui lit une variable interne ; l'appelant obtient la valeur courante sans modifier l'état. Les requêtes ne sont **pas** enregistrées dans l'historique.

Les requêtes servent à suivre une progression, lire un compteur, inspecter une liste d'éléments en attente.

### La mise à jour

Une **mise à jour** est un message transactionnel : le workflow la traite et **renvoie une réponse** à l'appelant. L'échange est enregistré dans l'historique. Les mises à jour combinent la sémantique du signal (changement d'état) et celle de la requête (valeur de retour).

La valeur de retour du gestionnaire *est* la réponse — c'est toute la différence avec un signal, qui n'en a pas :

```php
#[AsUpdateMethod('greet')]
public function greet(array $arguments): string
{
    $this->name = (string) ($arguments['name'] ?? 'World');

    return $this->name;   // ce que l'appelant reçoit
}
```

Un gestionnaire qui lève fait échouer la **mise à jour** : l'appelant reçoit l'échec, et l'exécution se poursuit. Au rejeu, le gestionnaire s'exécute de nouveau pour reconstruire l'état qu'il a modifié, tandis que le résultat enregistré reste celui que l'appelant a déjà reçu.

Les mises à jour servent à incrémenter un compteur en renvoyant la nouvelle valeur, ou à des approbations conditionnelles qui doivent rendre un accusé de réception.

---

## Les minuteurs

`WorkflowEnvironment::timer()` renvoie un `Awaitable` qui se résout après une durée. Comme les activités, les minuteurs sont enregistrés durablement : après un redémarrage, leur état est reconstruit depuis l'historique et le workflow repart au bon moment, sans réexécuter les minuteurs déjà échus.

```php
// Attendre 30 minutes avant de continuer
$env->await($env->timer(new \DateInterval('PT30M')));
```

---

## Les workflows enfants

Un workflow peut **lancer des workflows enfants** pour découper un processus complexe en sous-unités suivies indépendamment. Chaque enfant a son propre historique et se surveille séparément dans l'interface Temporal.

Un workflow enfant peut tourner de façon **asynchrone** (on le lance et on l'oublie) ou être attendu par le parent.

---

## Les backends

Durable tourne sur quatre backends qui partagent le même code de workflows et d'activités :

```
┌──────────────────────────────────────────────────────────────────────┐
│                           Code applicatif                            │
│             (workflows, activités, WorkflowEnvironment)              │
└───────────────────────────────────┬──────────────────────────────────┘
                                    │ même API
      ┌──────────────┬──────────────┼──────────────┐
      ▼              ▼              ▼              ▼
┌───────────┐  ┌───────────┐  ┌───────────┐  ┌───────────┐
│ En mémoire│  │    DBAL   │  │ Illuminate│  │  Temporal │
│   tests,  │  │  une base │  │  une base │  │un cluster,│
│   local   │  │    SQL,   │  │    SQL,   │  │à l'échelle│
│           │  │  Doctrine │  │  Laravel  │  │           │
└───────────┘  └───────────┘  └───────────┘  └───────────┘
```

### En mémoire

- Tourne entièrement dans un seul processus PHP.
- Aucun serveur externe, rien de persisté d'une requête à l'autre.
- L'envoi asynchrone des activités est simulé par un transport en processus.
- Le choix idéal pour tous les **tests automatisés** et les essais locaux rapides.

### DBAL

- Le journal, les métadonnées de reprise et les liens parent/enfant deviennent des tables dans **une
  seule base SQL**, à travers Doctrine DBAL.
- Aucun serveur d'orchestration, aucun sidecar, pas d'`ext-grpc`.
- Survit aux redémarrages et aux déploiements, à l'échelle qu'une base peut tenir.

### Illuminate

- Les mêmes quatre magasins et le même compromis, sur `Illuminate\Database\Connection` plutôt que
  sur celle de Doctrine — un magasin sur `DB::connection()` est dans `DB::transaction()` par
  construction.
- Ce n'est pas une quatrième valeur d'`event_store.type`, et ce ne le sera jamais : une application
  Laravel ne lit pas le YAML du bundle. Ce qui le branche, c'est `gplanchat/durable-laravel`, par son
  propre `config/durable.php`.

### Temporal

- Une orchestration de production, avec un vrai cluster Temporal.
- Historique persisté intégralement, réessais durables, interface Temporal — et les trois choses
  qu'aucun backend à journal n'a : les attributs de recherche, les planifications cron, et Nexus.
- Les workers interrogent Temporal en gRPC, par ce avec quoi l'hôte fait tourner ses workers.
- Nécessite l'extension PHP `ext-grpc`.

> [!NOTE]
> **Sur Magento, deux des quatre seulement sont atteignables.** `gplanchat/durable-magento` déclare
> un `conflict` Composer sur les deux ponts SQL — `Magento\Framework\App\ResourceConnection` n'est
> ni Doctrine DBAL ni la connexion d'Illuminate, aucun des deux n'a donc de quoi se brancher. L'état
> vit dans un cluster Temporal, ou il vit dans un processus, et c'est la présence de
> `durable/temporal/dsn` dans `app/etc/env.php` qui tranche.

Pour la mise en place, voir [Backends](../backends/).

---

## Par où le travail atteint un worker

Durable n'apporte aucun transport à lui. Le travail de workflow et d'activité roule sur ce que l'hôte
a déjà, et chaque hôte le dit autrement :

**Symfony** — **Messenger**. `ResumeWorkflowMessage` est routé vers la file des tâches de workflow,
`ActivityMessage` vers celle des activités, et les signaux, les mises à jour et les échéances de
minuteur partent sur le bus synchrone. Avec le backend Temporal, le transport est un **transport
d'interrogation adossé à gRPC** — même interface de consommateur, protocole sous-jacent différent.

**Laravel** — la **file que l'application draine déjà**. Les activités et les reprises sont des jobs,
un minuteur est une reprise différée sur le délai de la file, et `php artisan queue:work` est le seul
worker.

**Magento** — ni l'un ni l'autre. Les workers sont des commandes
`bin/magento durable:worker --role=journal|activity` qui interrogent le backend directement ; rien ne
roule sur le `MessageQueue` de Magento, parce que sur Temporal une activité est déjà une commande
Temporal et une reprise une tâche de workflow.

Pour la configuration, voir [Premiers pas](../getting-started/) et
[Référence de configuration](../configuration/).

---

## Déterminisme et contrat de rejeu

Le **contrat de rejeu** est la contrainte centrale : tout code à l'intérieur d'une `#[AsWorkflowMethod]` doit produire la **même suite d'opérations awaitables** à historique identique.

**Autorisé dans un workflow :**
- appeler les méthodes d'`activityStub()` → renvoie un `Awaitable` ;
- appeler `await()`, `all()`, `race()`, `any()` → suspendre ou combiner des awaitables ;
- appeler `timer()` → poser un minuteur ;
- lire l'état posé par `#[AsSignalMethod]` / `#[AsUpdateMethod]` ;
- tout calcul pur sur des variables locales au workflow.

**Interdit dans un workflow — à faire dans une activité :**
- `new \DateTime()` / `time()` / `random_int()` — non déterministes ;
- `file_get_contents()`, `curl_exec()`, requêtes en base ;
- état mutable `static` ou global partagé entre exécutions de workflow ;
- `sleep()`, la fonction PHP — utilisez `$env->sleep()`, qui suspend durablement au lieu de bloquer le worker.

---

## Pour aller plus loin

- [Premiers pas](../getting-started/) — installer, configurer, écrire un premier workflow de bout en bout.
- [Backends](../backends/) — en mémoire, DBAL, Illuminate et Temporal, mise en place Docker, référence des DSN.
- [Écrire un workflow](../workflows/) — l'API complète : signaux, requêtes, mises à jour, workflows enfants.
- [Écrire des activités](../activities/) — `ActivityOptions`, réessais, délais, injection de dépendances.
- [Tester des workflows](../testing/) — `DurableTestCase`, `ActivitySpy`, `DurableBundleTestTrait`.
- [Référence de configuration](../configuration/) — chaque clé de `durable.yaml`.
