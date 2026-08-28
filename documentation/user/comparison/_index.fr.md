---
title: Durable et le SDK PHP de Temporal
weight: 18
---

# Durable et le SDK PHP de Temporal

Temporal publie un [SDK PHP officiel](https://github.com/temporalio/sdk-php). Durable n'en est pas
un fork, n'est pas une surcouche, et n'en dépend pas : `composer.lock` ne contient ni `temporal/sdk`
ni aucun paquet RoadRunner. Les deux résolvent le même problème — l'exécution durable d'une logique
métier au long cours — et font des arbitrages différents à chaque couche en dessous.

Cette page énonce ces différences, y compris celles où le SDK est devant.

---

## 1. Le moteur du worker : pas de RoadRunner

Le SDK se scinde en un **client** et un **worker**. Le client exige `ext-grpc` ; le worker exige
**RoadRunner**, un serveur applicatif Go que l'on télécharge dans le projet par
`./vendor/bin/rr get` et que l'on configure par son propre `.rr.yaml`. Le code des workflows et des
activités tourne dans des processus PHP supervisés par RoadRunner.

Durable n'a pas de second moteur. Le travail de workflow et d'activité est acheminé par **Symfony
Messenger**, et un worker est un consommateur PHP en ligne de commande, ordinaire :

```bash
bin/console messenger:consume durable_workflows durable_activities
```

| | Durable | SDK PHP de Temporal |
|---|---|---|
| Processus du worker | `messenger:consume`, supervisé par ce qui supervise déjà vos workers | RoadRunner (binaire Go), supervisé par RoadRunner |
| Binaire supplémentaire dans l'image | non | oui |
| Configuration du worker | `messenger.yaml` | `.rr.yaml` |
| Modèle de déploiement | celui que votre application Symfony emploie déjà | un second modèle de processus à apprendre et à opérer |

### Ce que cela ne prétend *pas*

Durable ne supprime pas gRPC. Quand le backend est Temporal, le pont parle gRPC au cluster et
**`ext-grpc` est requis** — déclaré par `gplanchat/durable-bridge-temporal`, pas par le paquet
cœur :

| Paquet | Exige |
|---|---|
| `gplanchat/durable` | `php >= 8.2`, `psr/cache` — rien d'autre |
| `gplanchat/durable-bridge-temporal` | `ext-grpc`, `grpc/grpc`, `google/protobuf`, `symfony/messenger` |
| `gplanchat/durable-bridge-dbal` | `doctrine/dbal`, `symfony/lock`, `symfony/messenger` |
| `gplanchat/durable-bridge-illuminate` | `illuminate/database`, `illuminate/contracts` |

Donc : **jamais de RoadRunner ; `ext-grpc` seulement quand vous parlez à un cluster Temporal.** Sur
les backends en mémoire, DBAL et Illuminate, aucune extension PHP au-delà d'une installation
standard n'entre en jeu. La règle est consignée dans
[DUR006](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md).

---

## 2. La testabilité {#2-testability}

C'est là que les deux bibliothèques divergent le plus, et la divergence est structurelle plutôt
qu'une affaire d'outillage : elle découle de la façon dont un workflow atteint le moteur.

### Avec Durable, le workflow tourne dans le processus de test

`DurableTestCase` câble le backend en mémoire et fait tourner votre classe de production :

```php
final class GreetWorkflowTest extends DurableTestCase
{
    public function testWorkflowGreetsCorrectly(): void
    {
        $greetSpy = ActivitySpy::returns('Hello, Alice!');
        $env = $this->createWorkflowTestEnvironment(['greet' => $greetSpy]);

        $result = $env->runWorkflowClass(GreetingWorkflow::class, ['name' => 'Alice'], 'exec-1');

        self::assertSame('Hello, Alice!', $result);
        $greetSpy->assertCalledWith(['name' => 'Alice']);
        $this->assertWorkflowCompleted('exec-1', 'Hello, Alice!');
        $this->assertActivityExecuted('exec-1', 'greet');
    }
}
```

Pas de serveur, pas de binaire, pas d'extension, pas de Docker. Voir
[Tester des workflows](../testing/) pour la boîte à outils complète.

### Avec le SDK, tout test de workflow est un test d'intégration

L'environnement de test du SDK démarre un **serveur de test Temporal** *et* un **worker
RoadRunner**, depuis un fichier d'amorçage PHPUnit :

```php
// bootstrap.php
$environment = Temporal\Testing\Environment::create();
$environment->start();
register_shutdown_function(fn () => $environment->stop());
```

Le test pilote ensuite le workflow depuis l'extérieur, en gRPC, et l'observe à travers le client :

```php
$this->activityMocks->expectCompletion('SimpleActivity.doSomething', 'world');
$workflow = $this->workflowClient->newWorkflowStub(SimpleWorkflow::class);
$run = $this->workflowClient->start($workflow, 'hello');
$this->assertSame('world', $run->getResult('string'));
```

Le workflow ne s'exécute jamais dans le processus PHPUnit. Les doublures d'activité passent par un
canal hors processus : l'attente est écrite d'un côté et lue par le worker de l'autre. C'est
fidèle — c'est *un vrai* serveur Temporal — mais il n'existe aucun palier moins cher en dessous.
Vérifier qu'un `match` de votre workflow prend la bonne branche coûte deux binaires et un
aller-retour gRPC.

### Pourquoi Durable peut faire cela

Trois propriétés de la surface d'écriture, et non trois utilitaires de test :

- **L'environnement est injecté, pas statique.** `Workflow::newActivityStub()` lit un contexte
  statique lié au worker en marche, et lève `OutOfContextException` en dehors. Un workflow Durable
  reçoit son `WorkflowEnvironment` par son **constructeur** : c'est donc un objet PHP ordinaire
  qu'un test peut construire. Il n'y a aucun état global à réinitialiser entre les tests.
- **Des fibres au lieu de générateurs.** Une méthode de workflow rend son type déclaré. PHPUnit
  compare une valeur ; il ne pilote pas de générateur et ne résout pas de promesse.
- **Le test fait tourner la classe de production.** `runWorkflowClass()` passe par le même
  constructeur, les mêmes attributs, la même `#[AsWorkflowMethod]` — voir
  [DUR039](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR039-workflow-authoring-surface.md).

### Ce que vous pouvez vérifier

Durable expose le **journal d'événements** au test, et pas seulement la valeur de retour :

| `DurableTestCase` | `ActivitySpy` |
|---|---|
| `assertWorkflowCompleted()` | `ActivitySpy::returns()` / `throws()` / `returnsSequence()` |
| `assertWorkflowFailed($failureClass)` | `assertCalledWith()` / `assertFirstCallWith()` |
| `assertActivityExecuted()` | `assertCalledTimes()` / `assertCalledOnce()` / `assertNotCalled()` |
| `assertEventStoreContains($eventClass)` | `calls()` / `callCount()` |
| `countActivityExecutions()` | |

`countActivityExecutions()` est celle à garder en tête : elle prouve qu'une activité **n'a pas** été
rejouée après un réessai. Une assertion en boîte noire sur le résultat ne peut pas le voir.

Pour les tests d'intégration Symfony, `DurableBundleTestTrait` fait la même chose dans un
`KernelTestCase`, en vidant les transports Messenger jusqu'à ce que l'exécution se stabilise.

### Le palier qui, lui, a besoin d'un serveur

La suite d'intégration de Durable tourne contre un **vrai serveur Temporal** — `ext-grpc`, un
`temporal server start-dev` en marche, et des processus worker PHP lancés par le cas de test :

```bash
temporal server start-dev --namespace durable-test --port 7233
DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

La suite est ignorée quand `DURABLE_TEMPORAL_ADDRESS` n'est pas défini.

La différence avec le SDK n'est pas « pas de serveur » — c'est **quels tests en ont besoin**. Ce
palier existe pour prouver que les commandes du pont sont acceptées par un vrai serveur :
aller-retours, chemins d'échec, échéances, mises à jour, planifications cron, attributs de
recherche, Nexus. Il est délibérément étroit, et il porte sur le *pont*, pas sur votre logique
métier. Vos workflows sont couverts par le palier unitaire, qui n'a besoin de rien. Avec le SDK, le
palier adossé à un serveur est le seul qui existe.

| | Durable | SDK PHP de Temporal |
|---|---|---|
| Palier unitaire (logique métier) | PHPUnit, en processus, zéro infrastructure | aucun — tout test de workflow est hors processus |
| Palier adossé à un serveur | facultatif, cantonné à la parité de protocole | obligatoire, pour tous les tests de workflow |
| Ce qu'il exige | un serveur Temporal de dev + `ext-grpc` | serveur de test + RoadRunner |
| Tourne en intégration continue sans Docker | le palier unitaire, oui | non |

### Le coût, honnêtement

Un test en mémoire qui passe ne prouve pas que Temporal se comporte pareil. Ce risque est réel, et il
est encadré plutôt que nié :

- [DUR018](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR018-temporal-event-parity-replay-and-slots.md)
  exige la parité d'événements et d'emplacements entre l'en-mémoire et Temporal ;
- [DUR016](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR016-in-memory-backend-exception-rules.md)
  borne ce qu'une implémentation en mémoire a le droit de simplifier, et exige que chaque raccourci
  se justifie dans un docblock ;
- le palier d'intégration ci-dessus est ce qui le vérifie réellement.

Le saut de temps ne fait **pas** partie de ce que vous abandonnez. Le moteur en mémoire tient une
horloge virtuelle et l'avance jusqu'à l'échéance du prochain minuteur : `sleep(3600)` se résout en
une milliseconde de temps réel. Il ne saute que lorsque rien d'autre ne peut progresser — sauter
alors qu'une activité pourrait encore aboutir ferait gagner le minuteur à chaque course
`any(activité, minuteur)`. Voir
[Tester des workflows](../testing/#time-is-skipped-not-waited-for).

---

## 3. Les backends : un, ou trois

| | Durable | SDK PHP de Temporal |
|---|---|---|
| Backends d'exécution | **trois**, faisant tourner le même code de workflow | un cluster Temporal |
| Tests | en mémoire, sans serveur | serveur de test |
| Production sans cluster | **DBAL** — l'exécution durable sur une base SQL | impossible |

Le backend DBAL ([DUR030](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md))
n'a pas d'équivalent dans le SDK : un journal, des métadonnées de workflow et des verrous sur une
seule base relationnelle, sans cluster et sans `ext-grpc`. Pour une application qui a besoin
d'exécution durable mais pas de la surface opérationnelle d'un déploiement Temporal, c'est souvent
la différence qui tranche — davantage que le moteur du worker.

En changer est un changement de configuration (`durable.event_store.type`) ; le code du workflow ne
bouge pas. Voir [Backends](../backends/).

---

## 4. La surface d'écriture

Le même workflow — encaisser une commande, attendre une heure, envoyer le reçu — écrit deux fois.

**Durable** — environnement injecté, fibres, types de retour ordinaires :

```php
#[AsWorkflow(name: 'order')]
final class OrderWorkflow implements OrderWorkflowContract
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[AsWorkflowMethod]
    public function run(string $orderId): string
    {
        $activities = $this->environment->activityStub(OrderActivities::class);

        $charge = $this->environment->await($activities->charge($orderId));
        $this->environment->sleep(Duration::hours(1));

        return $this->environment->await($activities->sendReceipt($charge));
    }
}
```

**SDK PHP de Temporal** — façade statique, générateurs, promesses :

```php
#[WorkflowInterface]
interface OrderWorkflowContract
{
    #[AsWorkflowMethod]
    public function run(string $orderId);
}

final class OrderWorkflow implements OrderWorkflowContract
{
    public function run(string $orderId)
    {
        $activities = Workflow::newActivityStub(OrderActivities::class);

        $charge = yield $activities->charge($orderId);
        yield Workflow::timer(3600);

        return yield $activities->sendReceipt($charge);
    }
}
```

Mêmes étapes, mêmes noms, même ordre. Ce qui diffère, c'est tout ce qui les entoure :

| | Durable | SDK PHP de Temporal |
|---|---|---|
| Accès au moteur | `WorkflowEnvironment` injecté au constructeur | façade statique `Workflow::` |
| Suspension | fibres + `Awaitable` | `yield` + `React\Promise\PromiseInterface` |
| Coloration des fonctions | méthodes ordinaires, types de retour déclarés | toute méthode qui attend devient un générateur, et son appelant aussi — voir [plus bas](#5-fibers-or-generators-the-colouring-problem) |
| Déclaration | `#[AsWorkflow]` sur la classe | `#[WorkflowInterface]` sur une interface, implémentée par une classe |
| Attributs de méthode | `#[AsWorkflowMethod]`, `#[AsSignalMethod]`, `#[AsQueryMethod]`, `#[AsUpdateMethod]` | les mêmes quatre, mises à jour comprises |

Le type de retour en est la conséquence visible : `run()` déclare `string` d'un côté ; de l'autre, le
seul type qu'elle pourrait déclarer est `\Generator`, qui ne dit rien de ce que le workflow rend.
C'est ce qui fait de la classe Durable un objet ordinaire, qu'un test PHPUnit peut construire et
appeler — voir [La testabilité](#2-testability).

Le vocabulaire des attributs est délibérément proche ; le modèle d'exécution en dessous ne l'est pas.

---

## 5. Fibres ou générateurs : le problème de la coloration {#5-fibers-or-generators-the-colouring-problem}

La ligne *coloration des fonctions* ci-dessus est le mécanisme sous
[La testabilité](#2-testability) — c'est la deuxième des trois propriétés qui y sont listées, et
elle mérite sa propre section. Le nom vient de
[What Color Is Your Function?](https://journal.stuffwithstuff.com/2015/02/01/what-color-is-your-function/)
de Bob Nystrom : dans un langage où la suspension est un mot-clé, les fonctions ont deux couleurs —
la rouge suspend, la bleue non — et une rouge ne peut être appelée que depuis une autre rouge.

`yield` est ce mot-clé. Une méthode qui *yield* est un **générateur** : elle ne rend plus sa valeur,
elle rend un `Generator` que quelqu'un doit piloter. Extrayez trois lignes d'un workflow dans une
méthode d'aide — le remaniement le plus ordinaire — et si ces lignes attendent, l'aide devient
rouge, et tous ses appelants jusqu'à la méthode du workflow deviennent rouges avec elle.

**Durable** — l'aide est une méthode ordinaire :

```php
#[AsWorkflowMethod]
public function run(string $orderId): string
{
    return $this->chargeWithRetry($orderId);
}

private function chargeWithRetry(string $orderId): string
{
    foreach ([1, 2, 4] as $backoff) {
        try {
            return $this->environment->await($this->activities->charge($orderId));
        } catch (DurableActivityFailedException) {
            $this->environment->sleep(Duration::seconds($backoff));
        }
    }

    throw new ChargeGaveUp($orderId);
}
```

**SDK PHP de Temporal** — l'aide est un générateur, et son appelant aussi :

```php
public function run(string $orderId)
{
    return yield from $this->chargeWithRetry($orderId);
}

private function chargeWithRetry(string $orderId)
{
    foreach ([1, 2, 4] as $backoff) {
        try {
            return yield $this->activities->charge($orderId);
        } catch (ActivityFailure) {
            yield Workflow::timer($backoff);
        }
    }

    throw new ChargeGaveUp($orderId);
}
```

Une politique de réessai ferait normalement ce travail pour vous — `ActivityOptions` en porte une des
deux côtés, et [Échecs et réessais](../failures/) est sa place. Ce dont l'exemple parle, c'est de
l'**extraction** : trois lignes sorties d'une méthode de workflow vers une méthode d'aide. Deux
types de retour disparaissent, et le site d'appel devient `yield from`. Ni l'un ni l'autre n'est un
détail — c'est ce que la couleur coûte.

Durable suspend par `\Fiber::suspend()`, et il le fait **à l'intérieur du moteur**, dans
`ExecutionRuntime::await()`, plusieurs cadres sous votre code. Une fibre suspend toute la pile
d'appels, pas le cadre qui l'a demandé : les cadres intermédiaires sont suspendus sans participer,
ils n'ont donc besoin d'aucun mot-clé, d'aucun changement de type de retour, et d'aucune réécriture.

| | Durable (fibres) | SDK PHP de Temporal (générateurs) |
|---|---|---|
| Attendre depuis une méthode d'aide | une méthode privée ordinaire | l'aide devient un générateur |
| Ses appelants | inchangés | tous deviennent des générateurs aussi, jusqu'à `#[AsWorkflowMethod]` |
| Le site d'appel | `$this->chargeWithRetry($id)` | `yield from $this->chargeWithRetry($id)` |
| Type de retour déclaré | le sien — `string` | aucun qu'elle puisse utilement déclarer |
| L'appeler hors d'un workflow | un appel ordinaire | il faut de quoi piloter le générateur |

Cette dernière ligne est ce sur quoi [La testabilité](#2-testability) repose : un workflow bleu est
un objet que PHPUnit construit et appelle.

### Ce que la couleur achète, et ce qu'il en coûte d'y renoncer

La coloration n'est pas qu'un impôt. `yield` **marque le point de suspension dans le source** : en
lisant la méthode, vous savez exactement où le workflow peut s'arrêter une semaine. Les fibres
retirent ce marqueur : un appel d'apparence ordinaire peut suspendre, et rien au site d'appel ne le
dit.

Durable réduit la perte plutôt que de la nier. **Seul `await()` attend**, et `sleep()`, qui est un
`await()` sur minuteur écrit court — tout appel de stub, `timer()`, `all()`, `any()` et `some()`
assemblent et rendent la main immédiatement. À l'intérieur d'une méthode donnée, les points
d'attente sont exactement ces appels-là. Ce qu'un lecteur ne peut pas voir, c'est si une méthode
d'aide attend *à l'intérieur* — c'est le prix du remaniement que le SDK interdit.

Deux limites bonnes à connaître :

- les fibres sont du PHP **8.1+** ; Durable exige 8.2 de toute façon ;
- une fibre **ne peut pas suspendre dans un destructeur** — PHP lève `FiberError: Cannot switch
  fibers in current execution context`. Attendre depuis `__destruct()` n'est pas du code de
  workflow, si bien que le cas ne s'est pas présenté en pratique, mais c'est le seul contexte où la
  pile n'est pas libre de suspendre.

Aucun des deux modèles n'affecte le déterminisme : les deux rejouent le même historique, et les deux
interdisent les mêmes appels non déterministes dans un workflow. La différence est l'endroit où vit
le mot-clé de suspension — dans votre code, ou dans le moteur.

---

## 6. Planifier des activités

Le SDK accepte aussi bien un stub typé qu'un appel par nom d'activité avec une charge utile libre.
Durable a retiré la seconde forme : **le stub typé est le seul moyen pour un workflow de planifier
une activité**
([DUR039](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR039-workflow-authoring-surface.md)),
et l'extension facultative `gplanchat/durable-phpstan` résout les appels de stub contre l'interface
de contrat, si bien qu'un mauvais argument est une erreur d'analyse statique plutôt qu'un échec de
sérialisation à l'exécution.

Moins de liberté, une classe d'erreurs éliminée au moment de l'analyse. Voir
[Écrire des activités](../activities/).

---

## 7. Le versionnage de workflow : ce n'est plus un manque

Les deux laissent une même classe porter deux comportements, et laissent l'historique décider lequel
une exécution voit :

```php
// SDK PHP Temporal
$v = yield Workflow::getVersion('add-discount', Workflow::DEFAULT_VERSION, 1);

// Durable
$v = $this->environment->version('add-discount', ChangePoint::DEFAULT_VERSION, 1);
```

Le format sur le fil est le même, et pas par imitation : il a été lu dans un historique produit par
le SDK Go, puis émis depuis le pont et accepté par le serveur. Une exécution Durable versionnée et
une exécution Go versionnée enregistrent le **même** marqueur `Version` et le **même** attribut de
recherche `TemporalChangeVersion` — les deux reviennent donc de la même requête quand on demande qui
est encore sur une ancienne branche.

Deux différences demeurent, et aucune ne porte sur la primitive :

| | |
|---|---|
| **Versionnage des workers** | Identifiants de build, noms de déploiement, épinglage d'une exécution à une version de worker — le mécanisme d'exploitation qui vit dans le worker et la file, non dans le code du workflow. Le SDK l'a ; Durable non. |
| **Savoir qu'une branche est morte** | Une requête, sur le backend Temporal, pour les deux. Sur les backends à journal de Durable il n'y a pas d'attributs de recherche : la question n'y a pas de réponse équivalente. |

Voir [Changer un workflow qui tourne](../deploying/).

---

## 8. Nexus : le seul endroit où Durable est devant

[Nexus](https://docs.temporal.io/nexus) achemine un appel d'un workflow vers une opération servie
dans un autre espace de noms ou un autre cluster. **Un workflow Durable peut en appeler une, et
peut en servir une. Un workflow écrit avec le SDK PHP officiel ne peut ni l'un ni l'autre.**

```php
$checkout = $env->nexusStub(CheckoutContract::class, endpoint: 'checkout-endpoint');

$order = $env->await($checkout->placeOrder($cartId));
```

Le contrat s'écrit une fois et se lit des deux côtés, donc aucun nom d'opération n'est recopié en
chaîne. Cela compte parce que le serveur ne garde que le point d'entrée : il refuse d'emblée un nom
malformé, et accepte sans un mot un service ou une opération vide ou faite d'espaces — laissant
l'appel attendre un gestionnaire dont le nom ne correspondra jamais.

À l'heure où ces lignes sont écrites, « Nexus » n'apparaît dans le SDK PHP que comme de la plomberie
gRPC engendrée — CRUD de points d'entrée sur le client opérateur, une option d'emplacement de tâche
sur le worker, un vidage d'historique — sans aucune API qu'un workflow puisse atteindre. La
documentation de Temporal porte une section Nexus pour Go, Java, Python, TypeScript et .NET, et
aucune pour PHP. Côté Durable, le chemin appelant est éprouvé par des tests d'intégration contre un
vrai serveur Temporal : aller-retours, annulation et échec, bornes d'opération, les règles de
nommage du point d'entrée, du service, de l'opération et des en-têtes — et, côté gestionnaire, les
deux formes de réponse et le chemin d'annulation, un appelant Durable et un gestionnaire Durable
dans le même test.

### Servir, aussi

Un gestionnaire déclare l'opération qu'il sert, et répond maintenant ou plus tard :

```php
#[AsNexusServiceHandler(contract: FacturationServie::class)]
final class Facturation implements FacturationServie
{
    // Maintenant, si vous avez déjà la réponse — vous avez environ neuf secondes.
    public function verifier(Ordre $ordre): Verdict { /* … */ }
}

// Plus tard, pour tout ce qui est réel : un workflow réclame l'opération et produit le résultat.
#[AsWorkflow]
#[FulfilsNexusOperation(FacturationContract::class, 'encaisser')]
final class Encaissement { /* … */ }
```

Les neuf secondes ne sont pas une limite de Durable mais le `request-timeout` de la tâche, mesuré :
un gestionnaire encore au travail quand il expire voit sa tâche redélivrée et recommence. C'est
exactement ce budget qui justifie la forme différée, et pourquoi elle a été construite avant
l'immédiate.

L'annulation ne demande aucun crochet : Durable annule le workflow qui remplit l'opération, et un
workflow observe déjà son annulation avec ses compensations.

Voir [Opérations Nexus](../nexus/) pour toute la surface.

**Ce que ça change pour PHP.** Aucune autre implémentation PHP ne sert Nexus, parce qu'aucune autre
implémentation PHP n'atteint Nexus tout court. Jusqu'ici, un service PHP ne pouvait pas être
fournisseur Nexus : une équipe qui tourne en PHP était joignable en HTTP comme n'importe quel
service, mais pas à travers la frontière que Temporal donne à Go, Java, Python, TypeScript et .NET —
pas d'opération durable, pas de corrélation côté serveur, pas d'annulation qui suive l'appel. Cette
frontière est désormais ouverte à PHP.

Une limite demeure, et elle est délibérée :

- **Backend Temporal seulement.** Nexus achemine vers un point d'entrée servi ailleurs ; un backend
  qui garde son journal dans une seule base n'a ni cette route ni de repli honnête. Le backend DBAL
  **refuse donc immédiatement**, par `NexusUnsupportedByBackendException`, qui nomme le backend et
  dit quoi faire à la place, plutôt que de laisser le workflow attendre un résultat que personne ne
  produira. Côté gestionnaire, le même refus a lieu **au montage du conteneur**, et non à la
  requête — un gestionnaire sans route n'est pas un appel qui échoue, c'est un service qui ne
  reçoit jamais rien.

Le raisonnement est consigné dans
[DUR036](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR036-nexus-caller-only-and-the-backend-asymmetry.md)
et [DUR045](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR045-serving-a-nexus-operation.md).

---

## 9. Là où le SDK est devant

| | |
|---|---|
| **Maintenance** | Projet officiel de Temporal, tenu en parité avec les SDK des autres langages |
| **Maturité** | Un long historique en production. Durable est en `0.1.0-alpha`, avec des ruptures d'une alpha à l'autre |
| **Versionnage de workflow** | `Workflow::getVersion()` permet à une seule classe de porter les deux comportements et laisse l'historique décider lequel une exécution voit. **Durable n'a pas d'équivalent** : modifier un workflow avec des exécutions en vol suppose d'enregistrer un nouveau type de workflow et d'attendre que les anciennes s'écoulent. Le manque est réel, et c'est un manque de *confort* plutôt que de sûreté — un déploiement divergent est [attrapé et signalé](../deploying/), la tâche échoue, et revenir en arrière fait repartir l'exécution. Autrefois, il résolvait la mauvaise valeur enregistrée en silence |
| **Saga** | Un utilitaire dédié. Durable n'en a pas — la forme est une échéance et un chemin de compensation, écrits en toutes lettres dans [Écrire un workflow](../workflows/#bounding-a-wait-in-time) : ce qui manque est le sucre, pas la capacité |
| **Couverture de l'API** | Large. Durable couvre les attributs de recherche, les planifications cron, les mises à jour, les échéances et les workflows enfants — mais les attributs de recherche sont ici des **options de démarrage**, là où le SDK laisse aussi un workflow en cours mettre à jour les siens ; au-delà, cela vaut d'être vérifié dans la [référence de configuration](../configuration/) avant de s'engager |

Une comparaison sans colonne de pertes est du marketing. Celles-ci sont réelles — et la maturité
est celle qui pèse le plus lourd : `0.1.0-alpha` veut dire des ruptures entre versions, chacune
livrée avec sa procédure de migration, mais des ruptures tout de même.

---

## Choisir

**Prenez le SDK PHP de Temporal** quand vous opérez déjà un cluster Temporal, que vous voulez le
client officiellement maintenu et sa parité entre langages, que vous avez besoin du versionnage de
workflow ou d'un **gestionnaire** Nexus, et que RoadRunner est acceptable dans votre déploiement.

**Vous venez du SDK ?** `gplanchat/durable-rector` fait la partie mécanique : les attributs et les
classes d'échec, en conservant les **noms de type** de workflow et d'activité qu'un serveur en
marche connaît déjà — la partie qu'une migration à la main rate silencieusement — et le modèle
d'exécution, où la façade statique `Workflow::` devient un environnement injecté et où `yield`
disparaît, avec le type de retour `\Generator` qu'il laisse derrière lui. Ce qu'il ne fera pas,
c'est inventer le type de retour qui le remplace, ni convertir ce qui n'a pas d'équivalent ici : il
le commente, pour que vous sachiez avant de commencer si la migration vous est seulement ouverte.

**Prenez Durable** quand vous voulez l'exécution durable sans ajouter un second moteur à votre
application Symfony, quand une seule base SQL est la bonne empreinte opérationnelle, quand vous
voulez une logique de workflow couverte par des tests unitaires sans infrastructure, ou quand vous
avez besoin d'**appeler** des opérations Nexus depuis PHP tout court — et quand une alpha avec des
ruptures entre versions est un échange que vous pouvez faire.

---

## Voir aussi

- [Paquets](../packages/) — ce que chaque paquet contient et ce qu'il exige.
- [Backends](../backends/) — en mémoire, DBAL et Temporal côte à côte.
- [Tester des workflows](../testing/) — la boîte à outils de test complète.
- [Écrire un workflow](../workflows/) — la surface d'écriture en détail.
