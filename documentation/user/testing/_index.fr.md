---
title: Tester des workflows
weight: 40
---

# Tester des workflows

Durable embarque une **boîte à outils de test** pour valider vos workflows et vos activités avec
PHPUnit ordinaire. Deux points d'entrée, selon que vous écrivez des tests indépendants du framework
ou des tests d'intégration du bundle Symfony :

| Outil | Paquet | Quand l'employer |
|---|---|---|
| `DurableTestCase` + `ActivitySpy` + `WorkflowTestEnvironment` | `gplanchat/durable` | Tests unitaires ou fonctionnels purs, sans conteneur Symfony. |
| `DurableBundleTestTrait` | `gplanchat/durable-bundle` | Tests d'intégration Symfony fondés sur `KernelTestCase`. |

---

## Tests unitaires et fonctionnels — `DurableTestCase`

`DurableTestCase` est un `TestCase` PHPUnit abstrait qui câble pour vous un **backend en mémoire**.
Héritez-en, appelez `createWorkflowTestEnvironment()`, faites tourner votre workflow, et servez-vous
des assertions fournies.

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
        //    l'environnement arrive à son constructeur, l'entrée à sa #[WorkflowMethod].
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

Le workflow et le contrat sous test — les deux mêmes fichiers que vous écririez pour la production :

```php
interface GreetingActivities
{
    #[ActivityMethod('greet')]
    public function greet(string $name): string;
}

#[Workflow(name: 'greeting')]
final class GreetingWorkflow
{
    /** @var ActivityStub<GreetingActivities> */
    private readonly ActivityStub $greetings;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greetings = $environment->activityStub(GreetingActivities::class);
    }

    #[WorkflowMethod]
    public function run(string $name): string
    {
        return $this->environment->await($this->greetings->greet($name));
    }
}
```

> [!NOTE]
> `run()` accepte aussi une fermeture qui reçoit l'environnement, et quelques tests plus bas s'en
> servent pour un workflow de trois lignes qui ne vaut pas une classe. Cette forme est celle du
> **harnais**, pas celle d'un workflow : depuis que l'environnement est passé au constructeur,
> aucun vrai workflow n'a cette signature. Préférez `runWorkflowClass()` — ce que vous testez est
> alors ce que vous livrez.

### Les assertions de `DurableTestCase`

| Méthode | Description |
|---|---|
| `assertWorkflowCompleted($executionId, $expected)` | Le workflow a atteint `ExecutionCompleted` avec le résultat donné. |
| `assertWorkflowFailed($executionId, $class = '')` | Le workflow a atteint `WorkflowExecutionFailed`, avec éventuellement une classe d'exception précise. |
| `assertActivityExecuted($executionId, $name)` | Un événement `ActivityScheduled` portant ce nom existe dans le journal. |
| `assertEventStoreContains($executionId, $class)` | Un événement de la classe donnée est présent pour cette exécution. |
| `countActivityExecutions($executionId, $name)` | Renvoie combien de fois une activité nommée a été planifiée. |

---

## Piloter le comportement d'une activité — `ActivitySpy`

`ActivitySpy` est un **doublure de test appelable** pour les activités. Vous pouvez lui fixer une
valeur de retour, la faire lever, ou lui donner une séquence de résultats pour simuler des réessais.

### Toujours rendre la même valeur

```php
$spy = ActivitySpy::returns('fixed-result');
```

### Toujours lever une exception

```php
$spy = ActivitySpy::throws(new \RuntimeException('External API unavailable'));
```

### Rendre une séquence (pratique pour les scénarios de réessai)

Le premier appel rend la première valeur, le deuxième la deuxième, et ainsi de suite. Si un
`\Throwable` figure dans la séquence, il est **levé** à cette tentative-là. La dernière entrée est
répétée une fois la séquence épuisée.

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

## L'environnement de bas niveau — `WorkflowTestEnvironment`

`WorkflowTestEnvironment` est l'objet sur lequel `DurableTestCase` s'appuie. Vous pouvez l'employer
directement quand vous ne voulez pas hériter de `DurableTestCase`, par exemple dans des classes
utilitaires de test.

```php
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;

interface ShoutActivities
{
    #[ActivityMethod('my-activity')]
    public function shout(string $text): string;
}

$env = WorkflowTestEnvironment::inMemory(['my-activity' => fn(array $p) => strtoupper($p['text'])]);

$result = $env->run(function (WorkflowEnvironment $wf) {
    return $wf->await($wf->activityStub(ShoutActivities::class)->shout('hello'));
}, 'exec-001');

assert($result === 'HELLO');
```

`WorkflowTestEnvironment` expose :

- `run(callable $workflow, string $executionId): mixed` — faire tourner la fermeture du workflow ;
- `getEventStore(): EventStoreInterface` — lire le journal en mémoire ;
- `getRunner(): InMemoryWorkflowRunner` — accéder directement au moteur sous-jacent ;
- `getActivityTransport()` — inspecter la file d'activités en mémoire.

---

## Tests d'intégration Symfony — `DurableBundleTestTrait`

Pour les tests qui démarrent le noyau de votre application Symfony, employez `DurableBundleTestTrait`
dans n'importe quelle classe héritant de `KernelTestCase`. Le trait suppose que vos **transports
Messenger** de l'environnement `test` sont configurés **en mémoire** (voir
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

Dans `config/packages/messenger.yaml` (sous `when@test:`), assurez-vous d'avoir des transports en
mémoire dont les noms correspondent à `DurableBundleTestTrait::$durableWorkflowTransports` :

```yaml
when@test:
    framework:
        messenger:
            transports:
                durable_workflows:  'in-memory://'
                durable_activities: 'in-memory://'
```

### Adapter la liste des transports ou le délai de vidange

Redéfinissez les propriétés statiques avant chaque test :

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
vrai serveur vous dit qu'elles sont **acceptées**.

```bash
temporal server start-dev --namespace durable-test --port 7233

DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

Sans `DURABLE_TEMPORAL_ADDRESS`, la suite est ignorée : elle reste donc inoffensive dans une chaîne
qui n'a pas de serveur.

Deux workers tournent dans des **processus séparés**, comme en production. Les deux rôles font de
longues interrogations de plusieurs dizaines de secondes ; les alterner dans un seul processus
affamerait celui qui n'interroge pas.

Certains tests demandent une préparation au niveau de l'espace de noms, documentée en tête du
fichier qui en a besoin :

```bash
temporal operator search-attribute create --name DurableOrderId --type Keyword
temporal operator search-attribute create --name DurableAmount  --type Int
```

---

## Le temps est sauté, pas attendu {#time-is-skipped-not-waited-for}

Un workflow qui dort se teste en millisecondes. Le harnais tourne sur une **horloge virtuelle**
qu'il avance jusqu'au prochain minuteur échu : `sleep(Duration::hours(24))` ne coûte donc aucun
temps réel.

```php
interface PingActivities
{
    #[ActivityMethod('ping')]
    public function ping(): string;
}

$result = $env->run(function (WorkflowEnvironment $wf): string {
    $wf->sleep(Duration::hours(1));
    $answer = $wf->await($wf->activityStub(PingActivities::class)->ping());
    $wf->sleep(Duration::hours(24));

    return $answer;
}, 'nightly-1');
```

L'horloge n'avance que lorsque **rien d'autre ne peut progresser**. Sauter plus tôt ferait gagner le
minuteur à chaque course `any(activité, minuteur)` qu'une activité était sur le point de gagner —
ainsi une course se comporte ici comme en production.

Le recul entre réessais est une autre affaire : il consomme du temps réel, parce qu'un réessai est
mis en file sur le transport plutôt qu'enregistré comme un minuteur. Passez
`initialInterval: Duration::zero()` pour garder ces tests rapides.

---

## Deux pièges du moteur en mémoire

**Une exécution qui ne peut plus progresser échoue au lieu de se figer.** Un workflow qui attend un
signal que vous avez oublié de livrer lève `WorkflowStuckException` plutôt que de tourner à vide.

**Les tentatives sont illimitées par défaut.** Une activité qui échoue systématiquement réessaie
indéfiniment : le moteur impose donc un budget global, puis vous dit dans laquelle des deux
situations vous êtes :

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

Le recul entre réessais est honoré pour de vrai : une activité configurée avec l'intervalle par
défaut d'une seconde fait donc attendre le test. Passez `initialInterval: Duration::zero()` pour
garder les tests rapides.

---

## Tester les workflows enfants

Le harnais a besoin que les types enfants soient enregistrés, puisqu'il doit les résoudre par leur
nom :

```php
$env = WorkflowTestEnvironment::inMemory(['work' => $spy]);
$env->registerWorkflow('Child', fn (array $input) => fn (WorkflowEnvironment $wf) => /* … */);

$result = $env->run(
    fn (WorkflowEnvironment $wf) => $wf->await($wf->childWorkflowStub(ChildWorkflow::class)->run(21)),
    'parent-1',
);
```

`registerWorkflowClass()` prend à la place une classe de workflow annotée.
