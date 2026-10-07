---
title: Durable et le SDK PHP de Temporal
weight: 18
---

# Durable et le SDK PHP de Temporal

Temporal publie un [SDK PHP officiel](https://github.com/temporalio/sdk-php). Durable n'en dépend
pas, et n'en est ni un fork ni une surcouche : `composer.lock` ne contient ni `temporal/sdk` ni
aucun paquet RoadRunner. Les deux résolvent le même problème, l'exécution durable d'une logique
métier au long cours, et font des arbitrages différents à chaque couche en dessous.

Cette page décrit ces différences, y compris celles où le SDK est devant. Elle emploie quelques
termes de Durable : un workflow est la classe PHP qui décrit les étapes d'une exécution, une
activité est une unité d'effet de bord qu'il planifie, le journal est l'historique de tout ce qu'une
exécution a décidé et reçu, où chaque entrée s'ajoute sans jamais être réécrite, un backend est l'endroit où vit ce journal, et
un worker est le processus qui prend le travail. Le [glossaire](../glossary/) définit chacun d'eux.

**Quelle version du SDK.** Chaque affirmation ci-dessous a été vérifiée contre `temporal/sdk`
**v2.18**, publiée le 2026-08-17. Le SDK continue d'avancer, et ses mainteneurs ont dit
publiquement vouloir refermer deux des différences décrites ici ; les sections
[5](#5-fibers-or-generators-the-colouring-problem) et [8](#8-nexus-the-one-place-durable-is-ahead)
renvoient à ce travail public. Chaque différence vaut pour cette version et peut changer dans une
version ultérieure.

---

## 1. Le moteur du worker : pas de RoadRunner {#1-the-worker-runtime-no-roadrunner}

Le SDK se scinde en un **client** et un **worker**. Le client exige `ext-grpc`. Le worker exige
**RoadRunner**, un serveur applicatif Go que vous téléchargez dans le projet par
`./vendor/bin/rr get` et que vous configurez par son propre `.rr.yaml`. Le code des workflows et
des activités tourne dans des processus PHP que RoadRunner supervise.

Durable n'a pas de second moteur. Un worker est un processus PHP en ligne de commande ordinaire,
lancé par la console que votre framework hôte fournit déjà. Le transport de l'hôte achemine le
travail : Symfony Messenger, la file de Laravel ou, sur Magento, la file du backend que la commande
interroge elle-même :

```bash
bin/console messenger:consume durable_workflows durable_activities  # Symfony
php artisan queue:work                                              # Laravel
bin/magento durable:worker --role=journal                           # Magento
bin/magento durable:worker --role=activity                          #   (deux rôles, deux processus)
```

| | Durable | SDK PHP de Temporal |
|---|---|---|
| Processus du worker | `messenger:consume`, `queue:work` ou `bin/magento durable:worker`, supervisé par ce qui supervise déjà vos processus | RoadRunner (binaire Go), supervisé par RoadRunner |
| Binaire supplémentaire dans l'image | non | oui |
| Configuration du worker | `messenger.yaml`, `config/durable.php` ou `di.xml` | `.rr.yaml` |
| Modèle de déploiement | celui que votre application emploie déjà | un second modèle de processus à apprendre et à opérer |

### Quand Durable a encore besoin de gRPC {#ce-que-cela-ne-prétend-pas}

Durable ne supprime pas gRPC. Quand le backend est Temporal, le pont (le paquet qui relie Durable à
ce backend) parle gRPC au cluster, et **`ext-grpc` est requis**. Le paquet
`gplanchat/durable-bridge-temporal` déclare cette exigence :

| Paquet | Exige |
|---|---|
| `gplanchat/durable` | `php >= 8.2`, `psr/cache`, rien d'autre |
| `gplanchat/durable-bridge-temporal` | `ext-grpc`, `grpc/grpc`, `google/protobuf`, `symfony/messenger` |
| `gplanchat/durable-bridge-dbal` | `doctrine/dbal`, `symfony/lock`, `symfony/messenger` |
| `gplanchat/durable-bridge-illuminate` | `illuminate/database`, `illuminate/contracts` |
| `gplanchat/durable-laravel` | `illuminate/support`, `illuminate/container`, aucun composant Symfony |

Durable n'a jamais besoin de RoadRunner, et n'a besoin d'`ext-grpc` que si vous parlez à un
cluster Temporal. Les backends en mémoire, DBAL et Illuminate ne demandent aucune extension PHP
au-delà d'une installation standard.
[DUR006](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md)
consigne la règle qui fonde ce choix.

---

## 2. La testabilité {#2-testability}

C'est sur les tests que les deux bibliothèques diffèrent le plus. La différence découle de la façon
dont un workflow atteint le moteur : elle est structurelle, et l'outillage de test ne la supprime
pas.

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

Ce test n'a besoin ni de serveur, ni de binaire, ni d'extension, ni de Docker.
[Tester des workflows](../testing/) présente la boîte à outils complète.

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
canal hors processus : le test écrit l'attente d'un côté, et le worker la lit de l'autre. Le
dispositif est fidèle, puisqu'il fait tourner un vrai serveur Temporal, mais il n'a aucun palier
moins coûteux en dessous. Pour vérifier qu'un `match` de votre workflow prend la bonne branche,
vous payez deux binaires et un aller-retour gRPC.

### Pourquoi un workflow Durable peut tourner dans le processus de test {#pourquoi-durable-peut-faire-cela}

Trois propriétés de la surface d'écriture le permettent. Aucune n'est un utilitaire de test.

- **L'environnement est injecté.** Dans le SDK, `Workflow::newActivityStub()` lit un contexte
  statique lié au worker en marche, et lève `OutOfContextException` en dehors. Un workflow Durable
  reçoit son `WorkflowEnvironment` par son **constructeur** : un test le construit donc comme
  n'importe quel objet PHP. Aucun état global n'est à réinitialiser entre les tests.
- **Les fibres remplacent les générateurs.** Une méthode de workflow renvoie son type déclaré.
  PHPUnit compare une valeur ; il ne pilote pas de générateur et ne résout pas de promesse.
- **Le test fait tourner la classe de production.** `runWorkflowClass()` passe par le même
  constructeur, les mêmes attributs et la même `#[AsWorkflowMethod]` ; voir
  [DUR039](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR039-workflow-authoring-surface.md).

### Ce que vous pouvez vérifier

Durable expose au test le **journal d'événements**, en plus de la valeur de retour :

| `DurableTestCase` | `ActivitySpy` |
|---|---|
| `assertWorkflowCompleted()` | `ActivitySpy::returns()` / `throws()` / `returnsSequence()` |
| `assertWorkflowFailed($failureClass)` | `assertCalledWith()` / `assertFirstCallWith()` |
| `assertActivityExecuted()` | `assertCalledTimes()` / `assertCalledOnce()` / `assertNotCalled()` |
| `assertEventStoreContains($eventClass)` | `calls()` / `callCount()` |
| `countActivityExecutions()` | |

`countActivityExecutions()` prouve qu'une activité **n'a pas** été rejouée après un réessai. Une
assertion en boîte noire sur le résultat ne peut pas le voir.

Pour les tests d'intégration Symfony, `DurableBundleTestTrait` fait la même chose dans un
`KernelTestCase`, et vide les transports Messenger jusqu'à ce que l'exécution se stabilise.

### Le palier qui, lui, a besoin d'un serveur

La suite d'intégration de Durable tourne contre un **vrai serveur Temporal**. Elle demande
`ext-grpc`, un `temporal server start-dev` en marche, et des processus worker PHP que le cas de
test lance :

```bash
temporal server start-dev --namespace durable-test --port 7233
DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

PHPUnit ignore la suite quand `DURABLE_TEMPORAL_ADDRESS` n'est pas défini.

Les deux bibliothèques ont un palier adossé à un serveur. Elles diffèrent sur **les tests qui en
ont besoin**. Dans Durable, ce palier prouve qu'un vrai serveur accepte les commandes du pont :
aller-retours, chemins d'échec, échéances, mises à jour, planifications cron, attributs de
recherche, Nexus. Il est délibérément étroit et teste le *pont*. Le palier unitaire, qui n'a besoin
d'aucune infrastructure, couvre la logique métier de vos workflows. Avec le SDK, le palier adossé
à un serveur est le seul palier.

| | Durable | SDK PHP de Temporal |
|---|---|---|
| Palier unitaire (logique métier) | PHPUnit, en processus, zéro infrastructure | aucun ; tout test de workflow est hors processus |
| Palier adossé à un serveur | facultatif, cantonné à la parité de protocole | obligatoire, pour tous les tests de workflow |
| Ce qu'il exige | un serveur Temporal de dev + `ext-grpc` | serveur de test + RoadRunner |
| Tourne en intégration continue sans Docker | le palier unitaire, oui | non |

### Ce qu'un test en mémoire qui passe ne prouve pas {#le-coût-honnêtement}

Un test en mémoire qui passe ne prouve pas que Temporal se comporte de la même façon. Le risque est
réel, et trois mesures l'encadrent :

- [DUR018](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR018-temporal-event-parity-replay-and-slots.md)
  exige la parité d'événements et d'emplacements entre l'en-mémoire et Temporal ;
- [DUR016](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR016-in-memory-backend-exception-rules.md)
  borne ce qu'une implémentation en mémoire a le droit de simplifier, et exige un docblock qui
  justifie chaque raccourci ;
- le palier d'intégration ci-dessus est ce qui le vérifie réellement.

Vous gardez le saut de temps. Le moteur en mémoire tient une horloge virtuelle et l'avance jusqu'à
l'échéance du prochain minuteur, si bien que `sleep(3600)` se résout en une milliseconde de temps
réel. Il ne saute que lorsque rien d'autre ne peut progresser : sauter alors qu'une activité
pourrait encore aboutir ferait gagner le minuteur à chaque course `any(activité, minuteur)`. Voir
[Tester des workflows](../testing/#time-is-skipped-not-waited-for).

---

## 3. Les backends : un, ou quatre

| | Durable | SDK PHP de Temporal |
|---|---|---|
| Backends d'exécution | **quatre**, qui font tourner le même code de workflow | un cluster Temporal |
| Tests | en mémoire, sans serveur | serveur de test |
| Production sans cluster | **DBAL** ou **Illuminate**, l'exécution durable sur une base SQL | impossible |

Les quatre sont le backend en mémoire et trois ponts entre lesquels vous choisissez : Temporal,
DBAL et Illuminate.

Les deux backends SQL ([DUR030](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md)
sur la connexion de Doctrine, [DUR047](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR047-laravel-the-host-that-measured-before-it-wired.md)
sur celle de Laravel) n'ont pas d'équivalent dans le SDK. Ils gardent le journal, les métadonnées
de workflow et les verrous sur une seule base relationnelle, sans cluster et sans `ext-grpc`. Pour
une application qui a besoin d'exécution durable sans la surface opérationnelle d'un déploiement
Temporal, c'est souvent la différence qui tranche, davantage que le moteur du worker.

Changer de backend est un changement de configuration, et le réglage dépend de l'hôte. Sur
Symfony, `durable.backend` en accepte trois sur quatre (`in_memory`, `dbal`, `temporal`).
Illuminate est câblé par `gplanchat/durable-laravel` à travers son propre `config/durable.php`.
Dans les deux cas, le code du workflow reste le même. Voir [Backends](../backends/).

---

## 4. La surface d'écriture {#4-the-authoring-surface}

Voici le même workflow écrit deux fois. Il encaisse une commande, attend une heure, puis envoie le
reçu.

**Durable**, avec un environnement injecté, des fibres et des types de retour ordinaires :

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

**SDK PHP de Temporal**, avec une façade statique, des générateurs et des promesses :

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

Les étapes, leurs noms et leur ordre sont les mêmes. Le code qui les entoure diffère :

| | Durable | SDK PHP de Temporal |
|---|---|---|
| Accès au moteur | `WorkflowEnvironment` injecté au constructeur | façade statique `Workflow::` |
| Suspension | fibres + `Awaitable` | `yield` + `React\Promise\PromiseInterface` |
| Coloration des fonctions | méthodes ordinaires, types de retour déclarés | toute méthode qui attend devient un générateur, et son appelant aussi ; voir [plus bas](#5-fibers-or-generators-the-colouring-problem) |
| Déclaration | `#[AsWorkflow]` sur la classe | `#[WorkflowInterface]` sur une interface, implémentée par une classe |
| Attributs de méthode | `#[AsWorkflowMethod]`, `#[AsSignalMethod]`, `#[AsQueryMethod]`, `#[AsUpdateMethod]` | les mêmes quatre, mises à jour comprises |
| Compensations | `new Saga()` ; chaque compensation appelle `await()` elle-même, `compensate()` les exécute dans l'ordre inverse | `new Workflow\Saga()` ; `yield $saga->compensate()` |

Le type de retour montre la différence. Dans Durable, `run()` déclare `string`. Dans le SDK, le
seul type qu'elle pourrait déclarer est `\Generator`, qui ne dit rien de ce que le workflow
renvoie. Le type de retour déclaré est ce qui permet à un test PHPUnit de construire la classe
Durable et de l'appeler comme n'importe quel objet ; voir [La testabilité](#2-testability).

J'ai gardé à dessein un vocabulaire d'attributs proche de celui du SDK. Le modèle d'exécution en
dessous diffère.

---

## 5. Fibres ou générateurs : le problème de la coloration {#5-fibers-or-generators-the-colouring-problem}

La ligne *coloration des fonctions* ci-dessus est le mécanisme sous
[La testabilité](#2-testability), la deuxième des trois propriétés qui y sont listées. Cette
section l'examine en détail. Le nom vient de
[What Color Is Your Function?](https://journal.stuffwithstuff.com/2015/02/01/what-color-is-your-function/)
de Bob Nystrom. Si vous avez écrit du JavaScript, vous connaissez l'idée : une fonction qui emploie
`await` doit être déclarée `async`. Dans un langage où la suspension est un mot-clé, les fonctions
ont deux couleurs. La rouge suspend et la bleue non, et seule une autre rouge peut appeler une
rouge.

Avec les générateurs PHP, `yield` est ce mot-clé. Une méthode qui *yield* est un **générateur** :
elle ne renvoie plus sa valeur, elle renvoie un `Generator` qu'un autre code doit piloter.
Extrayez trois lignes d'un workflow dans une méthode d'aide, un remaniement ordinaire : si ces
lignes attendent, l'aide devient rouge, et tous ses appelants jusqu'à la méthode
du workflow deviennent rouges avec elle.

**Durable**, où l'aide est une méthode ordinaire :

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

**SDK PHP de Temporal**, où l'aide est un générateur, et son appelant aussi :

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

En pratique, une politique de réessai fait ce travail. `ActivityOptions` en porte une des deux
côtés, et [Échecs et réessais](../failures/) la présente. Cet exemple porte sur l'**extraction** :
trois lignes sorties d'une méthode de workflow vers une méthode d'aide. Côté SDK, deux types de
retour disparaissent et le site d'appel devient `yield from`. Ces deux changements sont le coût de
la couleur.

Durable suspend par `\Fiber::suspend()`, **à l'intérieur du moteur**, dans
`ExecutionRuntime::await()`, plusieurs cadres sous votre code. Une fibre suspend toute la pile
d'appels, et pas seulement le cadre qui l'a demandé. Les cadres intermédiaires sont suspendus sans
y participer : ils n'ont besoin d'aucun mot-clé, d'aucun changement de type de retour ni d'aucune
réécriture.

| | Durable (fibres) | SDK PHP de Temporal (générateurs) |
|---|---|---|
| Attendre depuis une méthode d'aide | une méthode privée ordinaire | l'aide devient un générateur |
| Ses appelants | inchangés | tous deviennent des générateurs aussi, jusqu'à `#[AsWorkflowMethod]` |
| Le site d'appel | `$this->chargeWithRetry($id)` | `yield from $this->chargeWithRetry($id)` |
| Type de retour déclaré | le sien, `string` | aucun qu'elle puisse utilement déclarer |
| L'appeler hors d'un workflow | un appel ordinaire | il faut de quoi piloter le générateur |

[La testabilité](#2-testability) repose sur cette dernière ligne : PHPUnit peut construire et
appeler un workflow bleu comme n'importe quel objet.

### Ce que la couleur montre, et ce que les fibres cachent {#ce-que-la-couleur-achète-et-ce-quil-en-coûte-dy-renoncer}

La coloration a aussi un avantage. `yield` **marque le point de suspension dans le source** : en
lisant la méthode, vous voyez exactement où le workflow peut s'arrêter une semaine. Les fibres
retirent ce marqueur. Un appel d'apparence ordinaire peut suspendre, et rien au site d'appel ne le
montre.

Durable limite cette perte. **Seul `await()` attend**, avec `sleep()`, qui est une écriture courte
d'un `await()` sur minuteur. Tout appel de stub, `timer()`, `all()`, `any()` et `some()`
construisent leur résultat et rendent la main immédiatement. À l'intérieur d'une méthode donnée,
les points d'attente sont exactement ces appels-là. Ce que vous ne voyez pas depuis le site
d'appel, c'est si une méthode d'aide attend *à l'intérieur*. C'est le prix du remaniement que le
modèle du SDK exclut.

Deux limites à connaître :

- les fibres demandent PHP **8.1+** ; Durable exige 8.2 de toute façon ;
- une fibre **ne peut pas suspendre dans un destructeur** : PHP lève `FiberError: Cannot switch
  fibers in current execution context`. Attendre depuis `__destruct()` n'est pas du code de
  workflow, si bien que le cas ne s'est pas présenté en pratique, mais c'est le seul contexte où la
  pile ne peut pas suspendre.

Aucun des deux modèles n'affecte le déterminisme. Les deux rejouent le même historique, et les
deux interdisent les mêmes appels non déterministes dans un workflow. Avec Durable, le mot-clé de
suspension vit dans le moteur ; avec le SDK, il vit dans votre code.

### Le support des fibres en cours dans le SDK {#le-sdk-compte-refermer-cet-écart}

Le SDK a une *pull request* ouverte qui ajoute une API de fibres
([#798](https://github.com/temporalio/sdk-php/pull/798)). Elle fait suite au ticket qui proposait
de remplacer les *yields* par une suspension de fibre
([#702](https://github.com/temporalio/sdk-php/issues/702)). Ses mainteneurs ont indiqué que le
changement est prototypé et prévu pour un prochain majeur. Rien de tout cela n'est dans une version
publiée à la v2.18, et cette section décrit la v2.18.

Ce changement réglerait la différence de coloration, et elle seule. Un test de workflow a
besoin d'un serveur à cause du [moteur du worker](#1-the-worker-runtime-no-roadrunner), quelle que
soit la façon dont le workflow suspend. Un workflow continue de tourner dans RoadRunner, piloté par une
file de tâches sur un vrai cluster, qu'il suspende sur un `yield` ou sur une fibre. Après ce
changement, [la testabilité](#2-testability) reste la plus grande différence : mener un workflow jusqu'au bout dans le processus de test et
vérifier la valeur qu'il renvoie, sans serveur à démarrer ni second moteur à superviser.

---

## 6. Planifier des activités

Le SDK accepte aussi bien un stub typé qu'un appel par nom d'activité avec une charge utile libre.
Durable retire la seconde forme : **le stub typé est le seul moyen pour un workflow de planifier
une activité**
([DUR039](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR039-workflow-authoring-surface.md)).
L'extension facultative `gplanchat/durable-phpstan` résout les appels de stub contre l'interface de
contrat : un mauvais argument devient une erreur d'analyse statique au lieu d'un échec de
sérialisation à l'exécution.

Vous renoncez à l'appel libre, et l'analyse statique détecte une classe d'erreurs. Voir
[Écrire des activités](../activities/).

---

## 7. Le versionnage de workflow

Les deux permettent à une même classe de porter deux comportements, et l'historique de l'exécution
détermine lequel elle voit :

```php
// SDK PHP Temporal
$v = yield Workflow::getVersion('add-discount', Workflow::DEFAULT_VERSION, 1);

// Durable
$v = $this->environment->version('add-discount', minSupported: ChangePoint::DEFAULT_VERSION, maxSupported: 1);
```

Le format sur le fil est le même. Je l'ai lu dans un historique produit par le SDK Go, puis émis
depuis le pont, et le serveur l'a accepté. Une
exécution Durable versionnée et une exécution Go versionnée enregistrent le **même** marqueur
`Version` et le **même** attribut de recherche `TemporalChangeVersion`. Quand vous cherchez les
exécutions encore sur une ancienne branche, la même requête renvoie les deux.

Les deux différences portent sur ce qui entoure la primitive :

| | |
|---|---|
| **Versionnage des workers** | Identifiants de build, noms de déploiement, épinglage d'une exécution à une version de worker : le mécanisme d'exploitation qui vit dans le worker et la file, hors du code du workflow. Le SDK l'a ; Durable non. |
| **Savoir qu'une branche est morte** | Une requête, sur le backend Temporal, pour les deux. Les backends à journal de Durable n'ont pas d'attributs de recherche, et n'offrent donc pas de réponse équivalente. |

Voir [Changer un workflow qui tourne](../deploying/).

---

## 8. Nexus : appeler et servir des opérations depuis PHP {#8-nexus-the-one-place-durable-is-ahead}

[Nexus](https://docs.temporal.io/nexus) achemine un appel d'un workflow vers une opération servie
dans un autre espace de noms ou un autre cluster. **Un workflow Durable peut appeler une opération
Nexus et peut en servir une. Un workflow écrit avec le SDK PHP officiel ne peut ni l'un ni
l'autre.**

```php
$checkout = $env->nexusStub(CheckoutContract::class, endpoint: 'checkout-endpoint');

$order = $env->await($checkout->placeOrder($cartId));
```

Vous écrivez le contrat une fois et les deux côtés le lisent : vous ne recopiez jamais un nom
d'opération en chaîne. Cela compte, parce que le serveur ne valide que le point d'entrée. Il
rejette d'emblée un point d'entrée malformé, mais accepte sans erreur un service ou une opération
vide ou fait d'espaces, et l'appel attend alors un gestionnaire dont le nom ne correspond jamais.

À la v2.18, « Nexus » n'apparaît dans le SDK PHP que comme de la plomberie gRPC générée (CRUD de
points d'entrée sur le client opérateur, une option d'emplacement de tâche sur le worker, un vidage
d'historique), sans aucune API qu'un workflow puisse atteindre. La documentation de Temporal a une
section Nexus pour Go, Java, Python, TypeScript et .NET, et aucune pour PHP.

**Le support de Nexus est en cours dans le SDK.** Une intégration est ouverte en *pull request*
([#768](https://github.com/temporalio/sdk-php/pull/768)), après le ticket qui a ouvert le sujet
([#580](https://github.com/temporalio/sdk-php/issues/580)), et ses mainteneurs l'ont annoncée pour
un prochain majeur. Lisez l'avance décrite dans cette section comme une avance qui se mesure en
versions : l'écart ne restera pas ouvert.

Côté Durable, des tests d'intégration contre un vrai serveur Temporal éprouvent le chemin
appelant : aller-retours, annulation et échec, bornes d'opération, et règles de nommage du point
d'entrée, du service, de l'opération et des en-têtes. Côté gestionnaire, ils couvrent les deux
formes de réponse et le chemin d'annulation, avec un appelant Durable et un gestionnaire Durable
dans le même test.

**L'appel interopère aussi avec les autres SDK.** La charge voyage telle que l'appelant l'a écrite,
sans emballage ni enveloppe, si bien qu'un gestionnaire écrit avec un autre SDK y lit les champs
qu'il déclare. La mesure a été faite contre un gestionnaire servi par le **SDK Go**, qui déclare
`Greeting{Name string}`, reçoit `{"name":"ada"}` et répond `hello ada`. Le sens inverse a été
mesuré aussi : un appelant Go qui invoque une opération servie par Durable récupère son propre type
déclaré, et les deux historiques sont identiques, événement par événement.

### Servir une opération Nexus {#servir-aussi}

Un gestionnaire déclare l'opération qu'il sert, et répond maintenant ou plus tard :

```php
#[AsNexusServiceHandler(contract: BillingContract::class)]
final class Billing implements BillingServed
{
    // Maintenant, si vous avez déjà la réponse : vous avez environ neuf secondes.
    public function verify(Order $order): Verdict { /* … */ }
}

// Plus tard, pour tout ce qui est réel : un workflow réclame l'opération et produit le résultat.
#[AsWorkflow('Charge')]
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class Charge { /* … */ }
```

Les neuf secondes sont le `request-timeout` de la tâche, que j'ai mesuré ; Durable n'ajoute aucune
limite.
Quand un gestionnaire travaille encore à l'expiration de ce délai, sa tâche est redélivrée et
recommence. Ce budget est la raison d'être de la forme différée, et la raison pour laquelle je l'ai
construite avant la forme immédiate.

L'annulation ne demande aucun crochet. Durable annule le workflow qui remplit l'opération, et un
workflow observe déjà sa propre annulation avec ses compensations.

[Opérations Nexus](../nexus/) présente toute la surface.

**Ce que cela change pour PHP.** Aucune autre implémentation PHP ne sert Nexus, parce qu'aucune
autre implémentation PHP n'atteint Nexus tout court. Jusqu'ici, un service PHP ne pouvait pas être
fournisseur Nexus. Une équipe qui tourne en PHP était joignable en HTTP comme n'importe quel
service, mais pas à travers la frontière que Temporal donne à Go, Java, Python, TypeScript et
.NET, avec ses opérations durables, sa corrélation côté serveur et son annulation qui suit l'appel.
Durable place PHP des deux côtés de cette frontière.

Une limite est délibérée :

- **Backend Temporal seulement.** Nexus achemine vers un point d'entrée servi ailleurs. Un backend
  qui garde son journal lui-même, en mémoire ou dans une seule base, n'a ni cette route ni de repli qui garde le sens de l'appel. Les backends en mémoire, DBAL et Illuminate
  **lèvent donc immédiatement** `NexusUnsupportedByBackendException`, dont le message indique
  d'utiliser le backend Temporal ; le workflow n'attend pas un résultat que personne ne produira.
  Côté gestionnaire, sur Symfony, la vérification échoue **au montage du conteneur** quand
  `durable.temporal.dsn` n'est pas renseigné, et non à la requête,
  parce qu'un gestionnaire sans route ne reçoit jamais aucune requête.

[DUR036](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR036-nexus-caller-only-and-the-backend-asymmetry.md)
et [DUR045](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR045-serving-a-nexus-operation.md)
consignent le raisonnement.

---

## 9. Là où le SDK est devant

| | |
|---|---|
| **Maintenance** | Projet officiel de Temporal, tenu en parité avec les SDK des autres langages |
| **Maturité** | Un long historique en production. Durable est en `0.1.0-beta`, une préversion : des ruptures d'une version à l'autre restent possibles |
| **Couverture de l'API** | Large. Durable couvre les attributs de recherche, les planifications cron, les mises à jour, les échéances et les workflows enfants, mais les attributs de recherche sont ici des **options de démarrage**, alors que le SDK permet aussi à un workflow en cours de mettre à jour les siens. Pour le reste, vérifiez dans la [référence de configuration](../configuration/) avant de vous engager |

Ces différences sont réelles, et la **maturité** pèse le plus lourd. `0.1.0-beta` reste une
préversion : des ruptures entre versions restent possibles. Chacune est livrée avec sa procédure de
migration, et reste une rupture.

---

## Choisir

**Prenez le SDK PHP de Temporal** quand vous opérez déjà un cluster Temporal, que vous voulez le
client officiellement maintenu et sa parité entre langages, que vous avez besoin du versionnage des
**workers** (identifiants de build, épinglage d'une exécution à une version de worker), et que
RoadRunner est acceptable dans votre déploiement.

**Vous venez du SDK ?** `gplanchat/durable-rector` fait la partie mécanique de la migration. Il
convertit les attributs et les classes d'échec, et conserve les **noms de type** de workflow et
d'activité déjà enregistrés sur un serveur en marche, la partie qu'une migration à la main rate
silencieusement. Il convertit aussi le modèle d'exécution : la façade statique `Workflow::` devient
un environnement injecté, et `yield` disparaît, avec le type de retour `\Generator` qu'il laisse
derrière lui. Il n'invente pas le type de retour qui remplace `\Generator`, et ne convertit pas ce
qui n'a pas d'équivalent dans Durable. Il ajoute un commentaire à ces endroits, pour que vous
sachiez avant de commencer si la migration vous est seulement ouverte.

**Prenez Durable** quand vous voulez l'exécution durable sans ajouter un second moteur à votre
application, quand une seule base SQL est la bonne empreinte opérationnelle, quand vous voulez une
logique de workflow couverte par des tests unitaires sans infrastructure, ou quand vous avez besoin
d'**appeler ou de servir** des opérations Nexus depuis PHP tout court. Dans chaque cas, vous devez
pouvoir accepter une préversion, avec des ruptures possibles entre versions.

---

## Voir aussi

- [Paquets](../packages/) décrit ce que chaque paquet contient et ce qu'il exige.
- [Backends](../backends/) compare la mémoire, DBAL, Illuminate et Temporal côte à côte.
- [Tester des workflows](../testing/) couvre la boîte à outils de test complète.
- [Écrire un workflow](../workflows/) couvre la surface d'écriture en détail.
