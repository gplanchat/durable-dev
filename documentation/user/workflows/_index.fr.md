---
title: Écrire un workflow
weight: 25
---

# Écrire un workflow

Cette page décrit comment vous **écrivez** un workflow avec Durable, et l'API dont se sert un
workflow. Un workflow est la classe PHP qui décrit les étapes d'une exécution (un déroulement
durable, de son démarrage à sa fin) ; ses effets de bord s'exécutent dans des activités, et chaque
étape est enregistrée dans le journal (l'historique, en ajout seul, d'une exécution). Le
[glossaire](../glossary/) définit chacun de ces termes.

Les règles normatives figurent dans les ADR de contribution [**DUR022**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR022-workflow-class-interface-and-workflow-environment.md) et les décisions voisines (**DUR003**, **DUR013**). Cette page en couvre l'usage pratique.

## Exemple : un workflow minimal

Un workflow est une **classe** portant **`#[AsWorkflow]`** et déclarée au moteur. Sa méthode de
workflow prend l'entrée, et Durable fournit le reste en arguments : les stubs d'activités et
l'environnement (voir [Les arguments que fournit Durable](#arguments-durable-supplies)). Avec le
chargeur actuel, l'attribut **`#[AsWorkflow]`** se pose sur la **classe** (voir [DUR022](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR022-workflow-class-interface-and-workflow-environment.md) pour le modèle « interface d'abord » visé à terme).

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow(name: 'order')]
final class OrderWorkflow
{
    /** @param ActivityStub<OrderActivities> $activities */
    #[AsWorkflowMethod]
    public function run(
        string $orderId,
        #[Activities(OrderActivities::class)]
        ActivityStub $activities,
        WorkflowEnvironment $env,
    ): mixed {
        // Contrat d'activité : voir « Écrire des activités ». Le stub planifie le travail ; await l'exécute dans le modèle de rejeu.
        return $env->await($activities->charge($orderId));
    }
}
```

`WorkflowEnvironment` fournit **`await`**, les assembleurs **`all`** / **`any`** / **`some`**, les minuteurs, les workflows enfants, les signaux, et le reste. L'API complète figure dans la classe elle-même, dans le dépôt.

### Attendre ou assembler {#waiting-versus-assembling}

**`await()` est la seule méthode qui attend.** Tous les autres appels assemblent. Un appel de
stub, `timer()` et les assembleurs ci-dessous renvoient tous un `Awaitable` et rendent la main
immédiatement.

```php
$env->sleep(Duration::minutes(5));            // attendre, et rien d'autre ; l'attente est faite pour vous

$winner = $env->await($env->any(              // assembler, puis attendre
    $activities->callProvider($orderId),
    $activities->callFallbackProvider($orderId),
));
```

Les trois assembleurs se distinguent par le nombre de membres qui doivent aboutir :

```php
$env->all($a, $b, $c)      // Awaitable de [$a, $b, $c] : tous les membres, dans l'ordre de déclaration
$env->any($a, $b, $c)      // Awaitable du premier membre à se résoudre, quel que soit son sort
$env->some(2, $a, $b, $c)  // Awaitable des 2 premiers membres à réussir, indexés par position
```

Comme ils renvoient un `Awaitable` au lieu d'une valeur, ils **se composent**. Un assemblage
s'imbrique dans un autre, et un assemblage peut être borné par une échéance.

```php
$quotes = $env->await($env->some(3, ...$providers), deadline: Duration::seconds(2));
```

`some()` ne compte que les membres qui **réussissent**. Un fournisseur qui échoue ne rapproche pas
du quorum, et dès qu'il ne reste plus assez de membres pour l'atteindre, l'attente échoue. `all()` est le quorum complet : un seul membre en échec fait
échouer tout l'assemblage. `any()` est une course : le premier membre à se résoudre gagne, même
s'il se résout en échouant.

Les branches perdantes sont annulées. Leurs activités sont retirées de la file et leurs minuteurs
ne réveillent plus l'exécution, y compris dans les branches imbriquées dans un assemblage.

`timer()` renvoie un `Awaitable` exactement comme un appel de stub : les deux se composent de la
même façon. Les deux acceptent une `Duration`, un `DateInterval` (donc un `CarbonInterval`), une
échéance `DateTimeInterface`, ou un simple nombre de secondes.

### Borner une attente dans le temps {#bounding-a-wait-in-time}

Pour renoncer à une attente au bout d'un moment, passez une **échéance** à `await()`. Ne mettez
pas un minuteur en course à la main : `any()` se résout à la **valeur** gagnante et à rien d'autre,
si bien qu'un fournisseur qui répond légitimement `null` devient indiscernable d'une échéance
écoulée, et qu'une saga qui compense au dépassement compenserait aussi sur une réponse vide.

```php
use Gplanchat\Durable\Exception\DeadlineExceededException;

try {
    $quote = $env->await($activities->callProvider($orderId), deadline: Duration::seconds(30));
} catch (DeadlineExceededException $e) {
    // Le fournisseur n'a pas répondu à temps. Chemin de compensation.
    // $e->deadline() est l'échéance écoulée, $e->awaited() ce qu'elle bornait.
}
```

L'échéance vaut `Duration::infinity()` par défaut. Une attente non bornée s'exprime donc par une
valeur et non par un argument manquant, et un appelant qui calcule sa propre échéance n'a pas de
cas « pas de borne » à traiter à part.

Une échéance écoulée lève une exception et ne renvoie jamais de valeur sentinelle. Quand le travail
se résout à temps, `null`, `false` et `[]` reviennent intacts.

### Attendre sur une condition

`await()` prend aussi une **condition**, un prédicat sur l'état du workflow lui-même, partout où
elle prend un awaitable, avec la même échéance facultative. Un gestionnaire de signal (une méthode
qui reçoit un message envoyé à l'exécution depuis l'extérieur ; voir le [glossaire](../glossary/))
modifie cet état et réveille l'attente :

```php
$env->onSignal(OrderSignal::Approve, fn(array $p) => $this->approvals[] = $p);

try {
    $env->await(fn(): bool => [] !== $this->approvals, deadline: Duration::hours(1));
} catch (DeadlineExceededException) {
    return $this->expire($orderId);
}
```

Cet exemple attend une approbation et renonce au bout d'une heure.

Pour décrire en mots **ce qu'une condition attend**, passez un `label`. Sur les backends qui
enregistrent l'attente (en mémoire, DBAL, Illuminate, Temporal), la liste des exécutions affiche
alors ce libellé au lieu de l'endroit où la closure est écrite, `waiting on signal approve` au lieu
de `waiting on condition at src/…/OrderWorkflow.php:42`. La grille Magento l'affiche sur Temporal (voir
la [page du tableau de bord](../dashboard/)).

```php
$env->await(fn(): bool => [] !== $this->approvals, deadline: Duration::hours(1), label: 'signal approve');
```

Le libellé est un texte d'affichage. Rien ne l'enregistre pendant que le workflow attend. Si
l'échéance expire et que rien n'attrape l'exception, le libellé figure dans le message d'échec que
garde le journal, écrit une seule fois. Le rejeu (la réexécution du code du workflow depuis sa
première ligne, où chaque étape enregistrée renvoie son résultat) ne le compare jamais : vous pouvez
ajouter, changer ou retirer un libellé sans risque. Un minuteur ou une activité se nomment déjà
eux-mêmes : passer un libellé à `await()` avec l'un d'eux lève une `InvalidArgumentException`.

#### Déclarer le gestionnaire et l'attente comme des méthodes {#le-gestionnaire-est-une-méthode-lattente-aussi}

Dans un workflow écrit en classe, déclarez le gestionnaire avec `#[AsSignalMethod]`, que le moteur
câble, et associez-lui une petite méthode privée qui attend et consomme. Le corps se lit alors en
une ligne, et le tampon est une propriété au lieu d'une référence capturée :

```php
#[AsWorkflow('Order')]
final class OrderWorkflow
{
    /** @var list<array<string, mixed>> */
    private array $approvals = [];

    public function __construct(private readonly WorkflowEnvironment $env) {}

    #[AsSignalMethod(OrderSignal::Approve)]
    public function approve(array $payload): void
    {
        $this->approvals[] = $payload;
    }

    #[AsWorkflowMethod]
    public function run(string $orderId): string
    {
        $approval = $this->waitApproval(Duration::hours(48));

        return $this->ship($orderId, $approval['by']);
    }

    /** @return array<string, mixed> */
    private function waitApproval(Duration $deadline): array
    {
        $this->env->await(fn(): bool => [] !== $this->approvals, deadline: $deadline);

        return array_shift($this->approvals);
    }
}
```

Les livraisons font partie de **l'état du workflow**. Un workflow qui attend trois fois le même
signal garde trois entrées et les consomme à son rythme, et un signal arrivé alors que rien
n'attendait est encore là à l'attente suivante. Le `waitSignal()` supprimé avait besoin d'un
compteur côté moteur pour s'en approcher ; ici, `array_shift()` suffit.

> [!WARNING]
> Deux erreurs ne produisent aucun message au moment où vous les commettez.
>
> L'attribut est lu sur la **classe du workflow**, avec `ReflectionClass::getMethods()`. PHP
> n'expose pas, à travers la classe qui l'implémente, un attribut déclaré sur une méthode
> d'interface : `#[AsSignalMethod]` posé sur une interface de contrat n'enregistre donc **rien**.
> Le signal arrive, aucun gestionnaire ne s'exécute, et la condition ne se réalise jamais. Posez
> l'attribut sur la classe.
>
> Le gestionnaire est appelé avec **un** argument, le tableau de charge utile. Une signature comme
> `approve(string $by)` échoue à la livraison du signal. Le démarrage du worker ne lève aucune erreur pour elle.


Une condition doit être fonction de **l'état du workflow et de rien d'autre**. Elle est réévaluée à
chaque rejeu : enregistrez une fois avec `sideEffect()` tout ce qu'un rejeu ne peut pas reproduire
(une horloge, un tirage aléatoire, une variable d'environnement), puis relisez-le :

```php
$threshold = $env->sideEffect(fn(): int => random_int(1, 10));   // enregistré une fois
$env->await(fn(): bool => $this->received >= $threshold);        // se rejoue à l'identique
```

Durable ne **détecte pas** une condition qui enfreint cette règle, ni aucun autre
non-déterminisme. Servez-vous de `sideEffect()` à la place.

> [!WARNING]
> `fn()` capture **par valeur**. Une condition portant sur une variable locale doit passer par la
> forme longue, `function () use (&$approvals): bool { … }`. Sur `$this->propriété`, la forme
> courte convient, car c'est `$this` qui est capturé, et la propriété est lue à travers lui.

Une condition qui ne peut jamais tenir, parce que rien de ce qui est en attente ne peut changer
l'état qu'elle lit, est signalée comme une exécution qui ne peut plus avancer, avec la condition
nommée par son fichier et sa ligne.

La branche perdante, quelle qu'elle soit, est annulée. Une échéance qui s'écoule annule le travail
qu'elle bornait, et un travail qui se résout annule l'échéance, si bien qu'aucun minuteur mort ne
réveille l'exécution plus tard. Annuler une activité en cours est un **effort au mieux**. Temporal
reçoit une *demande* d'annulation, et une tentative qui ne l'honore pas peut continuer de
s'exécuter sur son worker. Une fois l'échéance écoulée, la fin de cette tentative ne reprend plus
votre workflow.

Le verdict est lu dans l'historique enregistré : un rejeu atteint donc le verdict qu'a atteint
l'exécution d'origine, **y compris** quand le signal attendu est livré après l'échéance écoulée.
Un message enregistré après le déclenchement de l'échéance n'est jamais appliqué à l'attente que
cette échéance a tranchée. Il reste disponible pour l'attente suivante, et son gestionnaire
s'exécute à ce moment-là. Voir **DUR032** et **DUR035**.

### Les arguments que fournit Durable {#arguments-durable-supplies}

La méthode du workflow peut recevoir ses stubs d'activités et son environnement en arguments, au
lieu de les construire dans un constructeur :

```php
/** @param ActivityStub<OrderActivities> $orders */
#[AsWorkflowMethod]
public function run(
    string $orderId,
    #[Activities(OrderActivities::class)]
    ActivityStub $orders,
    WorkflowEnvironment $env,
): mixed {
    return $env->await($orders->charge($orderId));
}
```

- Un paramètre typé **`WorkflowEnvironment`** reçoit l'environnement.
- Un paramètre typé **`ActivityStub`** et marqué **`#[Activities(Contrat::class)]`** reçoit
  `$env->activityStub(Contrat::class)`. PHP n'a pas de génériques à l'exécution : c'est l'attribut
  qui nomme le contrat.
- Tout autre paramètre est une **entrée**, lue par son nom. Les paramètres fournis ne
  sont jamais passés par l'appelant : ni par le code qui démarre le workflow, ni par un parent qui
  l'appelle comme enfant, ni par une opération Nexus.
- Le docblock **`@param ActivityStub<Contrat>`** sert à PHPStan. Avec
  [`gplanchat/durable-phpstan`](../packages/), un docblock qui nomme un autre contrat que
  l'attribut est une erreur (`durable.activities.contractMismatch`), et son absence aussi
  (`durable.activities.missingGeneric`, qu'un projet peut ignorer), puisque PHPStan ne peut pas
  vérifier les appels sans lui.
- L'attribut prend aussi les **options** du stub, en scalaires. Un argument d'attribut ne peut pas
  appeler `Duration::seconds()`, les durées s'écrivent donc en secondes :

  ```php
  #[Activities(OrderActivities::class, attempts: 3, startToClose: 120.0, heartbeat: 10.0,
      nonRetryable: [CardDeclined::class], taskQueue: 'payments')]
  ActivityStub $activities,
  ```

  Disponibles : `attempts`, `startToClose`, `scheduleToClose`, `scheduleToStart`, `heartbeat`,
  `initialInterval`, `backoffCoefficient`, `maximumInterval`, `nonRetryable`, `taskQueue`,
  `cancellationType`, `summary`. Chaque option omise garde sa valeur par défaut
  d'`ActivityOptions` ; sans aucune, le stub est celui qu'`activityStub()` construit sans options. Sous Temporal, un stub
  sans `startToClose` ni `scheduleToClose` reçoit une borne de 30 secondes par tentative. `heartbeat`
  exige Temporal : un backend à journal lève `UnsupportedByBackendException` quand le stub planifie
  une activité avec cette option (voir [Options et objets valeur](../options/)).
- Les erreurs surviennent dès l'**enregistrement** du workflow (compilation du conteneur, avec le
  bundle) : un `ActivityStub` sans `#[Activities]`, un `#[Activities]` sur un autre type, un
  contrat introuvable, un contrat qui ne déclare aucun `#[AsActivityMethod]`, ou une option
  impossible (zéro tentative, une durée négative, un heartbeat plus long que `startToClose`, un
  `backoffCoefficient` inférieur à 1, un `maximumInterval` plus court que le premier délai de
  réessai, une entrée non réessayable qui n'est pas une exception). Le message nomme le paramètre et l'option.

### Quand construire le stub soi-même {#when-to-build-the-stub-yourself}

Injectez les stubs en arguments par défaut. Construisez-en un avec `$env->activityStub()` dans les
cas suivants, où l'attribut ne peut pas exprimer ce dont vous avez besoin :

- **La classe implémente une interface de contrat de workflow.** PHP n'autorise pas
  l'implémentation à ajouter des paramètres obligatoires à `run()` : l'environnement passe par le
  constructeur et le stub se construit à partir de lui. Voyez l'exemple ci-dessous.
- **Les options dépendent de l'entrée du workflow**, par exemple une file de tâches au nom d'un
  client. Un argument d'attribut est une constante : construisez un objet valeur `ActivityOptions`
  et passez-le en second argument d'`activityStub()`. Voyez
  [`ActivityOptions` sur le stub](#activityoptions-sur-le-stub).
- **Une méthode de signal ou d'update a besoin du stub.** Durable appelle ces méthodes avec la
  seule charge utile du message : le stub vient d'une propriété construite dans le constructeur.
- **Une méthode privée d'aide appelle l'activité.** Passez-lui le stub injecté en argument, ou
  gardez le stub dans une propriété construite dans le constructeur.
- **Le workflow est une fermeture** exécutée par le harnais de test
  (`WorkflowTestEnvironment::run()`) : elle ne reçoit que l'environnement. Voyez
  [Tester des workflows](../testing/).
- **Le stub est un stub Nexus ou un stub de workflow enfant.** Durable n'injecte que
  `WorkflowEnvironment` et `ActivityStub` ; construisez ceux-là avec `$env->nexusStub()` et
  `$env->childWorkflowStub()`.

La forme par constructeur avec une interface de contrat (facultative, mais utile pour les tests et
le typage) :

```php
/** Contrat métier. Aucun attribut requis sur l'interface. */
interface OrderWorkflowContract
{
    public function run(string $orderId): mixed;
}

#[AsWorkflow(name: 'order')]
final class OrderWorkflow implements OrderWorkflowContract
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[AsWorkflowMethod]
    public function run(string $orderId): mixed
    {
        $activities = $this->environment->activityStub(OrderActivities::class);

        return $this->environment->await($activities->charge($orderId));
    }
}
```

### `ActivityOptions` sur le stub

Pour appliquer **réessais**, **délais**, **file de tâches** et métadonnées de planification
voisines à tous les appels passant par un stub donné, donnez-les à **`#[Activities]`** sous forme de
scalaires, comme dans [Les arguments que fournit Durable](#arguments-durable-supplies). Quand ils
dépendent de l'entrée du workflow, passez un objet valeur **`ActivityOptions`** en second argument
d'**`activityStub()`** :

```php
use Gplanchat\Durable\Activity\ActivityOptions;

$options = ActivityOptions::of(5, 120, taskQueue: "payments-{$tenant}");   // 5 tentatives, 120 s chacune
$activities = $env->activityStub(OrderActivities::class, $options);
```

D'autres cas de figure dans [Écrire des activités : ActivityOptions](../activities/#activityoptions-timeouts-retries-task-queue),
et chaque option est décrite dans [Options et objets valeur](../options/).

### Nommage : `ActivityStub` ou `ActivityInvoker`

Les ADR emploient le terme canonique **`ActivityInvoker`** pour ce motif. Dans le paquet actuel, le
type s'appelle **`ActivityStub`** et vient de **`WorkflowEnvironment::activityStub()`**, dans le même
rôle : des appels typés qui renvoient un **`Awaitable`**. Le stub délègue à un port de planification
étroit qu'un workflow ne reçoit jamais, si bien que le code d'un workflow ne peut pas désigner une
activité par une chaîne.

## Exemple : deux méthodes d'entrée

Si vous exposez **deux** méthodes `#[AsWorkflowMethod]` sur le même type de workflow, **DUR022** exige qu'**exactement une** porte **`default: true`** sur l'attribut. Quand l'attribut expose ce paramètre dans votre version, le code ressemble à ceci :

```php
#[AsWorkflowMethod]
public function runMain(Input $input): mixed { /* ... */ }

#[AsWorkflowMethod(default: true)] // à titre d'illustration, à activer quand l'attribut le prendra en charge
public function runAlternate(Input $input): mixed { /* ... */ }
```

Tant que **`default`** n'existe pas sur **`#[AsWorkflowMethod]`**, suivez les règles d'enregistrement de votre moteur pour désigner l'entrée principale.

## Ce que vous définissez

1. Une **interface de workflow** (contrat facultatif) et/ou une **classe** portant **`#[AsWorkflow]`** (l'attribut se pose sur la **classe** avec les chargeurs actuels). C'est le contrat typé, pour l'enregistrement et pour les tests.
2. Une **classe concrète** déclarée au moteur. Si vous avez écrit une interface de contrat, la classe l'implémente (forme par constructeur).
3. **`WorkflowEnvironment`** et des stubs d'activités, rien d'autre : en [arguments de la méthode de workflow](#arguments-durable-supplies) ou, avec la forme par constructeur, comme son **unique** paramètre **`WorkflowEnvironment $environment`**. N'injectez **pas** de services, de dépôts ni d'autres dépendances applicatives dans la classe de workflow. Les effets de bord vont dans les [activités](../activities/).

## Registre : alias et nom pleinement qualifié

Quand vous enregistrez une classe de workflow, le moteur l'indexe sous **deux** chaînes : le **nom** donné à **`#[AsWorkflow]`** (premier argument), ou le **nom court** de la classe si l'attribut est absent, et le **nom de classe pleinement qualifié** (FQCN). **`WorkflowRegistry::getHandler()`** accepte **l'une ou l'autre** clé pour l'aiguillage.

**Temporal et le journal durable** emploient l'**alias** comme nom de type de workflow, jamais le FQCN. **`WorkflowRunHandler`** et **`TemporalWorkflowStarter`** normalisent les charges utiles de **`WorkflowRunMessage`** avec **`WorkflowDefinitionLoader::aliasForTemporalInterop()`** : un FQCN que vous passez est résolu en alias avant que **`ExecutionStarted`** ne soit persisté et avant que le **`WorkflowType`** Temporal ne soit posé. Les métadonnées stockées emploient l'alias, comme le serveur.

## Entrée et gestionnaires facultatifs {#entry-and-optional-handlers}

- Déclarez **au moins une** méthode portant **`#[AsWorkflowMethod]`**, votre entrée durable principale (le démarrage du scénario).
- Si vous exposez **plusieurs** méthodes `#[AsWorkflowMethod]` sur le même type de workflow, **exactement une** doit porter **`default: true`** pour désigner au moteur l'entrée principale.
- Ajoutez éventuellement ces gestionnaires :
  - **`#[AsSignalMethod]`** porte une entrée externe qui met à jour l'état du workflow de façon déterministe ;
  - **`#[AsQueryMethod]`** donne une vue en lecture seule de l'état (aucun effet de bord durable depuis le gestionnaire) ;
  - **`#[AsUpdateMethod]`** porte des mises à jour validées, avec sémantique de réponse quand elle est prise en charge.
    Sur Temporal, les mises à jour demandent un serveur 1.21 ou plus récent, et un réglage du
    serveur avant la 1.25 : voir les [prérequis de Temporal](../backends/#prérequis) sur la page Backends.

Paramètres et types de retour doivent être **sérialisables** (voir l'ADR de sérialisation **DUR007**).

## `WorkflowEnvironment`

Le moteur fournit **`WorkflowEnvironment`** en argument de la méthode de workflow, ou à votre
constructeur. Le tableau donne toute sa surface, chaque opération offerte à un workflow. Les
opérations que le moteur garde pour lui n'y figurent pas.

| | |
|---|---|
| `await($awaitable, $deadline = null)` | La seule attente. Une échéance écoulée lève `DeadlineExceededException` et ne renvoie aucune valeur, pour qu'un travail rendant légitimement `null` reste distinguable. |
| `all(...$awaitables)` | Se résout quand tous les membres réussissent. Un seul échec fait tout échouer. |
| `any(...$awaitables)` | Se résout au premier membre qui se résout ; les perdants sont annulés. |
| `some($count, ...$awaitables)` | Se résout quand `$count` membres ont **réussi**, indexés par position de déclaration. Les autres sont annulés. |
| `timer($duration, $summary = '')` | Un awaitable qui se résout à l'échéance de la durée. Se compose comme n'importe quel autre. |
| `sleep($duration, $summary = '')` | Attend la durée, comme `await(timer($duration, $summary))`. |
| `activityStub($contract, $options = null)` | Un proxy typé sur un contrat d'activité. Construisez-le dans le constructeur, ou déclarez-le en [argument `#[Activities]`](#arguments-durable-supplies), options comprises ; tous ses appels portent `$options`. |
| `childWorkflowStub($class, $options = null)` | Le même, pour un workflow enfant : résolu depuis la classe de l'enfant, et ses appels se composent comme les autres. |
| `nexusStub($contract, $endpoint, $timeouts = null)` | Un proxy typé sur un contrat Nexus servi à `$endpoint`. Ses appels renvoient des awaitables. Voir [Opérations Nexus](../nexus/#appeler-une-opération). |
| `nexusOperation($endpoint, $service, $operation, $payload = [], $timeouts = null)` | Appelle une opération Nexus par les noms de son endpoint, de son service et de l'opération, et renvoie un awaitable. Lève `NexusUnsupportedByBackendException` sur un backend qui ne peut pas acheminer l'appel. |
| `onSignal($name, $handler)` | Enregistre un gestionnaire de signal. Le gestionnaire mute l'état du workflow et `await()` l'observe ; il n'y a pas d'attente séparée. Le nom prend une énumération adossée, donc une faute de frappe donne une erreur de type au lieu d'une attente qui ne se résout jamais. |
| `onUpdate($name, $handler)` | Le même pour une mise à jour, dont la valeur de retour du gestionnaire est la réponse rendue à l'appelant. |
| `hasSignalHandler($name)`, `hasUpdateHandler($name)` | Indique si un gestionnaire est enregistré sous ce nom, pour le code qui n'en enregistre un qu'une fois. |
| `sideEffect($closure)` | Exécute une fois un travail local non déterministe et en journalise le résultat, pour que le rejeu le reproduise. |
| `version($changeId, $minSupported, $maxSupported)` | Déclare un point de changement et renvoie la version que suit cette exécution, entre `$minSupported` et `$maxSupported`. La réponse est fixée à la première rencontre, puis relue dans le journal. Voir [Modifier un workflow déjà en cours d'exécution](../deploying/#ou-déclarer-un-point-de-changement). |
| `continueAsNew($type, $payload = [], $options = null)` | Termine cette exécution et démarre la suivante avec un historique neuf. |
| `executionId()` | L'identifiant de cette exécution, un `ExecutionId`. Appelez `toString()` pour le mettre dans une charge utile ou un contexte de log : en JSON, l'objet devient `{}`. |

Les activités ne sont joignables **qu'**à travers un stub. Cette surface n'offre aucun moyen d'en
désigner une par une chaîne, avec une charge utile libre. Une faute de frappe y produirait une
activité jamais planifiée, là où un appel de stub donne une erreur que votre IDE et votre
analyseur statique attrapent d'abord.

Les gestionnaires de requête, de signal et de mise à jour se déclarent par `#[AsQueryMethod]`,
`#[AsSignalMethod]` et `#[AsUpdateMethod]`, et le moteur les câble. Signaux et mises à jour peuvent
aussi s'enregistrer de façon impérative, par `onSignal()` et `onUpdate()`. Un workflow exprimé sous
forme de fermeture doit les employer, puisqu'une fermeture ne peut pas porter d'attribut. Préférez
l'attribut, qu'un lecteur voit sans rien exécuter.

**Les requêtes n'ont pas de forme impérative.** Elles sont lues par le worker, hors de la fibre du
workflow : leurs gestionnaires vivent côté moteur et `#[AsQueryMethod]` est le seul moyen d'en
déclarer un. Un workflow en forme de fermeture ne peut pas répondre à une requête. Pour répondre à une
requête, un workflow doit être une classe.

`WorkflowEnvironment::wrap($context, $runtime)` construit un environnement sur un `ExecutionContext`
sans les résolveurs de contrats. Servez-vous-en dans un exécuteur ou un harnais de test à vous. Le
code du workflow reçoit toujours l'environnement construit par le moteur.

Vous n'instanciez jamais d'implémentation d'activité dans le corps du workflow.

## Aide-mémoire

| Règle | Détail |
|-------|--------|
| Constructeur | Aucun n'est nécessaire ; avec la forme par constructeur, `WorkflowEnvironment` et rien d'autre |
| Contrat | `#[AsWorkflow]` sur la classe ; l'interface est facultative, et si la classe en implémente une, c'est la forme par constructeur |
| Entrée | Au moins une `#[AsWorkflowMethod]` ; `default: true` s'il y en a plusieurs |
| E/S | Aucune dans le workflow ; passez par des activités |
| Appels au travail | Par un **`ActivityStub`**, reçu en argument `#[Activities]` ou construit à partir de l'environnement |
| `finally` | S'exécute à chaque passe qui se suspend, pas une fois par exécution ; n'y mettez pas de travail |

## `finally` s'exécute à chaque passe qui se suspend

D'une passe à l'autre, Durable ne garde pas en mémoire un workflow qui attend. Chaque passe le
rejoue jusqu'à la prochaine attente, puis l'abandonne, et PHP exécute ses blocs `finally` à ce
moment-là.
Les SDK Java et Go de Temporal font de même quand ils évincent un workflow de leur cache.

```php
try {
    return $env->await($this->payments->charge($order));
} finally {
    $this->released = true;                       // s'exécute à chaque passe qui attend ici
    $this->inventory->release($order);            // refusé pendant l'abandon de la passe
}
```

- **Le code ordinaire d'un `finally` s'exécute à chaque passe.** Affecter un champ, ou ajouter à
  un journal tenu dans le workflow, se refait à chaque fois que le workflow reprend et attend de
  nouveau.
- **Le travail lancé depuis un `finally` échoue pendant l'abandon de la passe.** Une activité,
  un timer, un enfant ou un side effect lancé à ce moment n'atteint jamais le journal, et un
  `await` n'y attend pas. La passe se termine comme elle l'aurait fait sans le `finally`.
- **Sur la passe où le workflow se termine vraiment**, par un retour, un échec ou une annulation,
  le `finally` s'exécute normalement, et le travail qu'il lance est enregistré.

Placez un nettoyage qui doit avoir lieu une seule fois dans un `catch`, ou après le `try`, là où le
workflow n'arrive que lorsqu'il y arrive vraiment. Une compensation s'écrit ainsi ; voir
[Annulation](../cancellation/).

## Voir aussi

- [Concepts](../concepts/) couvre workflow contre activité, rejeu et backends.
- [Écrire des activités](../activities/) couvre les interfaces d'activité, `#[AsActivityMethod]` et **`ActivityInvoker`**.
