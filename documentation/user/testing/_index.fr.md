---
title: Tester des workflows
weight: 40
---

# Tester des workflows

Durable fournit une **boîte à outils de test** pour vos workflows et vos activités, fondée sur
PHPUnit standard. Un workflow décrit les étapes d'une exécution, et une activité est l'une de ces
étapes qui a un effet de bord, comme un appel HTTP ; voir le [glossaire](../glossary/). Choisissez
le point d'entrée qui correspond à vos tests, indépendants du framework, d'intégration du bundle
Symfony ou d'intégration Laravel :

| Outil | Paquet | Quand l'employer |
|---|---|---|
| `DurableTestCase` + `ActivitySpy` + `WorkflowTestEnvironment` | `gplanchat/durable` | Tests unitaires ou fonctionnels purs, sans conteneur Symfony. |
| `DurableBundleTestTrait` | `gplanchat/durable-bundle` | Tests d'intégration Symfony fondés sur `KernelTestCase`. |
| `DurableLaravelTestTrait` | `gplanchat/durable-laravel` | Tests d'intégration Laravel, sur le backend configuré de l'application. |

---

## Tests unitaires et fonctionnels avec `DurableTestCase` {#tests-unitaires-et-fonctionnels--durabletestcase}

`DurableTestCase` est un `TestCase` PHPUnit abstrait qui câble pour vous un **backend en mémoire** :
le journal, qui enregistre les étapes d'une exécution et leurs résultats, reste en mémoire.
Héritez-en, appelez `createWorkflowTestEnvironment()`, faites tourner votre workflow, puis vérifiez
le résultat avec les assertions fournies.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Workflow\GreetWorkflow;
use Gplanchat\Durable\Testing\ActivitySpy;
use Gplanchat\Durable\Testing\DurableTestCase;
use Gplanchat\Durable\WorkflowEnvironment;

final class GreetWorkflowTest extends DurableTestCase
{
    public function testWorkflowGreetsCorrectly(): void
    {
        // 1. Un espion qui rend une valeur fixe quand l'activité est appelée.
        $greetSpy = ActivitySpy::returns('Hello, Alice!');

        // 2. Un environnement en mémoire, où l'espion est enregistré sous le nom de l'activité.
        $env = $this->createWorkflowTestEnvironment(['greet' => $greetSpy]);

        // 3. On fait tourner la classe de workflow, dans la forme qu'elle a en production :
        //    l'environnement arrive à son constructeur, l'entrée à sa #[AsWorkflowMethod].
        $result = $env->runWorkflowClass(
            GreetingWorkflow::class,
            ['name' => 'Alice'],
            $executionId = 'exec-greet-001',
        );

        // 4. On vérifie le résultat et l'appel à l'activité. Le stub reconstruit la charge
        //    utile depuis les noms de paramètres du contrat, et c'est ce que l'espion observe.
        self::assertSame('Hello, Alice!', $result);
        $greetSpy->assertCalledTimes(1);
        $greetSpy->assertCalledWith(['name' => 'Alice']);

        // 5. On vérifie les invariants du journal (facultatif, pour une couverture plus profonde).
        $this->assertWorkflowCompleted($executionId, 'Hello, Alice!');
        $this->assertActivityExecuted($executionId, 'greet');
    }
}
```

Le workflow et le contrat sous test, les deux mêmes fichiers que vous écririez pour la production :

```php
interface GreetingActivities
{
    #[AsActivityMethod('greet')]
    public function greet(string $name): string;
}

#[AsWorkflow(name: 'greeting')]
final class GreetingWorkflow
{
    /** @param ActivityStub<GreetingActivities> $greetings */
    #[AsWorkflowMethod]
    public function run(
        string $name,
        #[Activities(GreetingActivities::class)]
        ActivityStub $greetings,
        WorkflowEnvironment $env,
    ): string {
        return $env->await($greetings->greet($name));
    }
}
```

> [!NOTE]
> `run()` accepte aussi une fermeture qui reçoit l'environnement, et quelques tests plus bas s'en
> servent pour un workflow de trois lignes qui ne vaut pas une classe. Cette forme est celle du
> **harnais**, pas celle d'un workflow : une classe de workflow reçoit l'environnement et ses
> stubs en paramètres de sa méthode de workflow, ou l'environnement par son constructeur. Préférez
> `runWorkflowClass()`, ce que vous testez est alors ce que vous livrez.

### Les assertions de `DurableTestCase`

| Méthode | Description |
|---|---|
| `assertWorkflowCompleted($executionId, $expected)` | Le workflow a atteint `ExecutionCompleted` avec le résultat donné. |
| `assertWorkflowFailed($executionId, $class = '')` | Le workflow a atteint `WorkflowExecutionFailed`, avec éventuellement une classe d'exception précise. |
| `assertActivityExecuted($executionId, $name)` | Un événement `ActivityScheduled` portant ce nom existe dans le journal. |
| `assertEventStoreContains($executionId, $class)` | Un événement de la classe donnée est présent pour cette exécution. |
| `countActivityExecutions($executionId, $name)` | Renvoie combien de fois une activité nommée a été planifiée. |

---

## Piloter le comportement d'une activité avec `ActivitySpy` {#piloter-le-comportement-dune-activité--activityspy}

`ActivitySpy` est une **doublure de test appelable** pour les activités. Fixez sa valeur de retour,
faites-la lever une exception, ou donnez-lui une séquence de résultats pour simuler des réessais.

### Toujours rendre la même valeur

```php
$spy = ActivitySpy::returns('fixed-result');
```

### Toujours lever une exception

```php
$spy = ActivitySpy::throws(new \RuntimeException('External API unavailable'));
```

### Rendre une séquence (pratique pour les scénarios de réessai)

Le premier appel rend la première valeur, le deuxième la deuxième, et ainsi de suite. Un
`\Throwable` placé dans la séquence est **levé** à sa tentative. Une fois la séquence épuisée,
l'espion répète la dernière entrée.

```php
$spy = ActivitySpy::returnsSequence(
    new \RuntimeException('Temporary failure'), // tentative 1 → lève
    new \RuntimeException('Still failing'),     // tentative 2 → lève
    'Success after retries',                    // tentative 3 → rend
);
```

### Inspecter les appels

```php
$spy->calls();          // la liste des charges utiles reçues, p. ex. [['name' => 'Alice']]
$spy->callCount();      // combien de fois l'espion a été appelé

$spy->assertCalledTimes(1);
$spy->assertCalledWith(['name' => 'Alice']);          // premier appel
$spy->assertCalledWith(['name' => 'Bob'], index: 1); // deuxième appel (index à partir de 0)
$spy->assertNeverCalled();
```

---

## L'environnement de bas niveau : `WorkflowTestEnvironment`

`DurableTestCase` s'appuie sur `WorkflowTestEnvironment`. Employez-le directement quand vous ne
voulez pas hériter de `DurableTestCase`, par exemple dans des classes utilitaires de test.

```php
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;

interface ShoutActivities
{
    #[AsActivityMethod('my-activity')]
    public function shout(string $text): string;
}

$env = WorkflowTestEnvironment::inMemory(['my-activity' => fn(array $p) => strtoupper($p['text'])]);

$result = $env->run(function (WorkflowEnvironment $wf) {
    return $wf->await($wf->activityStub(ShoutActivities::class)->shout('hello'));
}, 'exec-001');

assert($result === 'HELLO');
```

`WorkflowTestEnvironment` expose :

- `run(callable $workflow, string $executionId): mixed` fait tourner la fermeture du workflow ;
- `getEventStore(): EventStoreInterface` lit le journal en mémoire ;
- `getRunner(): InMemoryWorkflowRunner` donne accès au moteur sous-jacent ;
- `getActivityTransport()` inspecte la file d'activités en mémoire.

---

## Tests d'intégration Symfony avec `DurableBundleTestTrait` {#tests-dintégration-symfony--durablebundletesttrait}

Pour les tests qui démarrent le noyau de votre application Symfony, employez `DurableBundleTestTrait`
dans n'importe quelle classe héritant de `KernelTestCase`. Le trait repose sur des
**transports Messenger** configurés **en mémoire** dans l'environnement `test` (voir
[Premiers pas](../getting-started/)).

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Workflow\OrderWorkflow;
use Gplanchat\Durable\Bundle\Testing\DurableBundleTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrderWorkflowIntegrationTest extends KernelTestCase
{
    use DurableBundleTestTrait;

    public function testOrderWorkflowCompletesSuccessfully(): void
    {
        self::bootKernel();

        // On envoie le workflow dans le transport Messenger en mémoire.
        $executionId = $this->dispatchWorkflow(OrderWorkflow::class, [
            'orderId' => 'ORD-123',
            'amount'  => 99.90,
        ]);

        // On vide les transports jusqu'à ce que le workflow atteigne un état terminal.
        $this->drainMessengerUntilSettled($executionId);

        // On vérifie le résultat final.
        $this->assertWorkflowResultEquals($executionId, ['status' => 'charged', 'orderId' => 'ORD-123']);
    }

    public function testOrderWorkflowFailsWhenAmountIsNegative(): void
    {
        self::bootKernel();

        $executionId = $this->dispatchWorkflow(OrderWorkflow::class, [
            'orderId' => 'ORD-999',
            'amount'  => -1.0,
        ]);

        $this->drainMessengerUntilSettled($executionId);

        $this->assertWorkflowFailed($executionId, \InvalidArgumentException::class);
    }
}
```

### Prérequis

Dans `config/packages/messenger.yaml`, sous `when@test:`, déclarez des transports en mémoire dont
les noms correspondent à `DurableBundleTestTrait::$durableWorkflowTransports` :

```yaml
when@test:
    framework:
        messenger:
            transports:
                durable_workflows:  'in-memory://'
                durable_activities: 'in-memory://'
```

### Adapter la liste des transports ou le délai de vidange

Pour changer la liste des transports ou le délai de vidange, redéfinissez les propriétés statiques avant chaque test :

```php
protected function setUp(): void
{
    parent::setUp();
    // Ajoutez un nom de transport si votre application en déclare un.
    static::$durableWorkflowTransports = ['durable_workflows', 'durable_activities', 'my_custom_transport'];
    // Allongez le temps de vidange maximum (en secondes) pour des machines d'intégration lentes.
    static::$durableMaxDrainSeconds = 60.0;
}
```

### Les méthodes de `DurableBundleTestTrait`

| Méthode | Description |
|---|---|
| `dispatchWorkflow($class, $input, $executionId?)` | Envoie un workflow et renvoie son `executionId`. |
| `drainMessengerUntilSettled($executionId)` | Traite les messages de tous les transports configurés jusqu'à ce que le workflow se termine. Lève si le délai est atteint. |
| `assertWorkflowResultEquals($executionId, $expected)` | Vérifie que le workflow s'est terminé avec le résultat donné. |
| `assertWorkflowFailed($executionId, $class?)` | Vérifie que le workflow a échoué, avec éventuellement la classe d'exception. |
| `getEventStoreService()` | Renvoie l'`EventStoreInterface` du conteneur de test, pour une inspection de bas niveau. |
| `getDataCollector()` | Renvoie le `DurableDataCollector` quand le profileur est actif (noyau de débogage). |

---

## Tests d'intégration Laravel avec `DurableLaravelTestTrait` {#tests-dintégration-laravel--durablelaraveltesttrait}

Employez `DurableLaravelTestTrait` dans une classe de test qui étend le `TestCase` de Laravel
(`Illuminate\Foundation\Testing\TestCase`, ou celui de Testbench), qui fournit `$this->app`. Le
trait offre les mêmes quatre opérations que celui de Symfony, sur le backend que configure
l'application. Déclarez le workflow dans la clé `workflows` de `config/durable.php`, comme en
production.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Workflow\OrderWorkflow;
use Gplanchat\Durable\Laravel\Testing\DurableLaravelTestTrait;
use Tests\TestCase;

final class OrderWorkflowTest extends TestCase
{
    use DurableLaravelTestTrait;

    public function testOrderWorkflowCompletesSuccessfully(): void
    {
        $executionId = $this->dispatchWorkflow(OrderWorkflow::class, [
            'orderId' => 'ORD-123',
            'amount'  => 99.90,
        ]);

        $this->drainUntilSettled($executionId);

        $this->assertWorkflowResultEquals($executionId, ['status' => 'charged', 'orderId' => 'ORD-123']);
    }
}
```

`drainUntilSettled()` dépend du backend. Sur `memory`, il exécute les runs que le processus a mis en
file, comme `durable:drain`, et lève une `\RuntimeException` si le run est encore ouvert à la fin de
la vidange, par exemple quand il attend un signal. Sur `illuminate`, il lance
`queue:work --stop-when-empty` jusqu'à ce que le run se termine ou échoue, dans la limite de 30
secondes. Un workflow qui échoue termine la vidange sans lever : vérifiez-le avec
`assertWorkflowFailed()`.

### Les helpers Symfony et Laravel côte à côte {#les-trois-hôtes-côte-à-côte}

| Opération | Symfony (`DurableBundleTestTrait`) | Laravel (`DurableLaravelTestTrait`) |
|---|---|---|
| Démarrer | `dispatchWorkflow($class, $input, $executionId?)` | `dispatchWorkflow($class, $input, $executionId?)` |
| Vidanger | `drainMessengerUntilSettled($executionId)` | `drainUntilSettled($executionId)` |
| Lire le résultat | `assertWorkflowResultEquals($executionId, $expected)` | `assertWorkflowResultEquals($executionId, $expected)` |
| Vérifier le journal | `assertWorkflowFailed($executionId, $class?)` | `assertWorkflowFailed($executionId, $class?)` |
| Magasin d'événements | `getEventStoreService()` | `getEventStoreService()` |

Le trait Symfony offre aussi `getDataCollector()`, pour le profileur. Magento n'a pas encore d'aide.

---

## Choisir le bon niveau de test

```
Unitaire / fonctionnel (sans conteneur)
  └── DurableTestCase + ActivitySpy
       → Rapide, déterministe, isolé. Idéal pour la logique de workflow.

Intégration Symfony (avec conteneur)
  └── KernelTestCase + DurableBundleTestTrait
       → Éprouve le câblage d'injection, le routage Messenger, l'injection dans les
          gestionnaires d'activité. Un peu plus lent ; à réserver aux scénarios
          « chemin nominal » de bout en bout.

Intégration Temporal (vrai serveur Temporal)
  └── tests/integration, joués contre un serveur de développement
       → Vérifie que les commandes sont *acceptées*, pas seulement bien formées.
```

---

## Tester contre un vrai serveur Temporal

Les tests unitaires vérifient que le pont construit des commandes protobuf bien formées. Seul un
vrai serveur montre s'il les **accepte**.

```bash
temporal server start-dev --namespace durable-test --port 7233

DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

Sans `DURABLE_TEMPORAL_ADDRESS`, la suite est ignorée : elle reste donc inoffensive dans une
chaîne qui n'a pas de serveur.

La suite fait tourner deux workers dans des **processus séparés**, comme en production. Les deux
rôles font de longues interrogations de plusieurs dizaines de secondes, et les alterner dans un
seul processus affame le rôle qui n'interroge pas.

Certains tests demandent une préparation au niveau de l'espace de noms. Le fichier concerné la
documente en tête :

```bash
temporal operator search-attribute create --name DurableOrderId --type Keyword
temporal operator search-attribute create --name DurableAmount  --type Int
temporal operator search-attribute create --name DurablePrice   --type Double
```

---

## Les minuteurs tournent sur une horloge virtuelle en test {#time-is-skipped-not-waited-for}

Dans les tests, un workflow qui dort s'exécute en quelques millisecondes. Le harnais emploie une
**horloge virtuelle** et l'avance jusqu'au prochain minuteur échu : `sleep(Duration::hours(24))` ne
prend donc aucun temps réel.

```php
interface PingActivities
{
    #[AsActivityMethod('ping')]
    public function ping(): string;
}

$result = $env->run(function (WorkflowEnvironment $wf): string {
    $wf->sleep(Duration::hours(1));
    $answer = $wf->await($wf->activityStub(PingActivities::class)->ping());
    $wf->sleep(Duration::hours(24));

    return $answer;
}, 'nightly-1');
```

L'horloge n'avance que lorsque **rien d'autre ne peut progresser**. L'avancer plus tôt ferait
gagner le minuteur à chaque course `any(activité, minuteur)` que l'activité était sur le point de
gagner. Comme l'horloge attend, une course a ici la même issue qu'en production.

---

## Exécutions bloquées, réessais et chaînes sans fin dans le moteur en mémoire {#deux-pièges-du-moteur-en-mémoire}

**Une exécution bloquée échoue.** Un workflow qui attend un signal que le test ne livre jamais lève `WorkflowStuckException`.

**Les tentatives sont illimitées par défaut.** Une activité qui échoue systématiquement réessaie
indéfiniment : le moteur impose donc un budget global. Quand le budget est épuisé, il indique
laquelle des deux situations s'applique :

```
Workflow x did not finish within 10.0s. Activities retry indefinitely by default
(RetryLimit::unlimited(), Temporal semantics): pass RetryLimit::ofAttempts(n) or
RetryLimit::once(), declare the exception non-retryable, or raise the runner budget.
```

```php
$env = WorkflowTestEnvironment::inMemory(
    ['charge' => $spy],
    budgetSeconds: 3.0,
);
```

Le recul entre réessais prend du temps réel, parce qu'un réessai est mis en file sur le transport au
lieu d'être enregistré comme un minuteur : une activité configurée avec l'intervalle par défaut
d'une seconde fait donc attendre le test. Passez `initialInterval: Duration::zero()` pour garder
les tests rapides.

**Une chaîne de continue-as-new s'arrête après 10 continuations.** Une exécution qui appelle
`continueAsNew()` referme son journal et passe la main à une exécution neuve (continue-as-new ; voir
le [glossaire](../glossary/)). Le moteur en mémoire suit la chaîne et renvoie le résultat de la
dernière exécution. Chaque exécution de la chaîne a son propre budget, qui n'arrête donc pas un
workflow appelant `continueAsNew()` à chaque fois. Au-delà de 10 continuations, le moteur lève
`ContinuationCapReachedException`, une `WorkflowStuckException`, où `x` est l'identifiant de
l'exécution que vous avez démarrée :

```
Workflow x continued as new more often than maxContinuations (10) allows. Give the workflow a run
that returns, or raise the runner's maxContinuations.
```

Pour tester une chaîne plus longue, relevez le plafond :

```php
$env = WorkflowTestEnvironment::inMemory(maxContinuations: 50);
```

Un workflow enfant exécuté dans le processus garde le plafond par défaut de 10, comme il garde le
budget par défaut, quel que soit le plafond de l'environnement de son parent.

Dans un `DurableTestCase`, `createWorkflowTestEnvironment()` et `createWorkflowRunner()` prennent
les deux mêmes arguments, `budgetSeconds` et `maxContinuations`, et les transmettent au moteur :

```php
$env = $this->createWorkflowTestEnvironment(
    ['charge' => $spy],
    budgetSeconds: 3.0,
    maxContinuations: 50,
);
```

Sous Magento sans DSN Temporal, réglez
l'argument `maxContinuations` de `RuntimeFactory` dans `di.xml`, comme pour `budgetSeconds`.

---

## Tester les workflows enfants

Enregistrez les types de workflows enfants auprès du harnais, qui les résout par leur nom :

```php
$env = WorkflowTestEnvironment::inMemory(['work' => $spy]);
$env->registerWorkflow('Child', fn (array $input) => fn (WorkflowEnvironment $wf) => /* … */);

$result = $env->run(
    fn (WorkflowEnvironment $wf) => $wf->await($wf->childWorkflowStub(ChildWorkflow::class)->run(21)),
    'parent-1',
);
```

Pour enregistrer une classe de workflow qui porte les attributs, employez plutôt
`registerWorkflowClass()`.
