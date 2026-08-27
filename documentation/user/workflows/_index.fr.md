---
title: Écrire un workflow
weight: 25
---

# Écrire un workflow

Cette page résume comment on **écrit** un workflow en Durable. Les règles normatives vivent dans les ADR de contribution [**DUR022**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR022-workflow-class-interface-and-workflow-environment.md) et les décisions voisines (**DUR003**, **DUR013**) ; ce guide reste pratique.

## Exemple : un workflow minimal

On définit une **interface de contrat** (facultative, mais recommandée pour les tests et le typage) et une **classe concrète** déclarée au moteur. L'attribut **`#[Workflow]`** se pose sur la **classe** avec le chargeur actuel (voir [DUR022](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR022-workflow-class-interface-and-workflow-environment.md) pour le modèle « interface d'abord » visé à terme).

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/** Contrat métier — aucun attribut requis sur l'interface. */
interface OrderWorkflowContract
{
    public function run(string $orderId): mixed;
}

#[Workflow(name: 'order')]
final class OrderWorkflow implements OrderWorkflowContract
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(string $orderId): mixed
    {
        // Contrat d'activité : voir « Écrire des activités ». Le stub planifie le travail ; await l'exécute dans le modèle de rejeu.
        $activities = $this->environment->activityStub(OrderActivities::class);

        return $this->environment->await($activities->charge($orderId));
    }
}
```

`WorkflowEnvironment` fournit **`await`**, les assembleurs **`all`** / **`any`** / **`some`**, **`async`**, les minuteurs, les workflows enfants, les signaux, et le reste — voir la classe dans le dépôt pour l'API complète.

### Attendre ou assembler {#waiting-versus-assembling}

**`await()` est la seule méthode qui attend.** Tout le reste assemble : un appel de stub, `timer()`.
Les appels de stub comme les assembleurs ci-dessous renvoient tous un `Awaitable`, et rendent la
main immédiatement.

```php
$env->sleep(Duration::minutes(5));            // attendre, et rien d'autre — l'attente est faite pour vous

$winner = $env->await($env->any(              // assembler, puis attendre
    $activities->callProvider($orderId),
    $activities->callFallbackProvider($orderId),
));
```

Trois assembleurs, selon le nombre de membres qui doivent aboutir :

```php
$env->all($a, $b, $c)      // Awaitable de [$a, $b, $c] — tous les membres, dans l'ordre de déclaration
$env->any($a, $b, $c)      // Awaitable du premier membre à se résoudre, quel que soit son sort
$env->some(2, $a, $b, $c)  // Awaitable des 2 premiers membres à réussir, indexés par position
```

Parce qu'ils renvoient un `Awaitable` et non une valeur, ils **se composent** : un assemblage
s'imbrique dans un autre et — c'est ce qui compte le plus — un assemblage peut être borné par une
échéance.

```php
$quotes = $env->await($env->some(3, ...$providers), Duration::seconds(2));
```

`some()` ne compte que les membres qui **réussissent** : un fournisseur qui échoue ne rapproche pas
du quorum, et dès qu'il n'en reste plus assez pour l'atteindre, l'attente échoue au lieu de ne
jamais se résoudre. `all()` est le quorum complet : un seul membre en échec fait échouer tout
l'assemblage. `any()` est une course : le premier membre à se résoudre gagne, même en échouant.

Les branches perdantes sont annulées — activités retirées de la file, minuteurs empêchés de
réveiller l'exécution — y compris les branches imbriquées dans un assemblage.

`timer()` renvoie un `Awaitable` exactement comme un appel de stub : les deux se composent de la
même façon. Les deux acceptent une `Duration`, un `DateInterval` (donc un `CarbonInterval`), une
échéance `DateTimeInterface`, ou un simple nombre de secondes.

### Borner une attente dans le temps {#bounding-a-wait-in-time}

Pour renoncer à une attente au bout d'un moment, passez une **échéance** à `await()` — ne courez
pas un minuteur à la main. `any()` se résout à la **valeur** gagnante et à rien d'autre : un
fournisseur qui répond légitimement `null` devient indistinguable d'une échéance écoulée, et une
saga qui compense au dépassement compenserait aussi sur une réponse vide.

```php
use Gplanchat\Durable\Exception\DeadlineExceededException;

try {
    $quote = $env->await($activities->callProvider($orderId), Duration::seconds(30));
} catch (DeadlineExceededException $e) {
    // Le fournisseur n'a pas répondu à temps — chemin de compensation.
    // $e->deadline() est l'échéance écoulée, $e->awaited() ce qu'elle bornait.
}
```

L'échéance vaut `Duration::infinity()` par défaut — une attente non bornée le dit par une valeur
plutôt que par un argument manquant, si bien qu'un appelant qui calcule sa propre échéance n'a pas
de cas « pas de borne » à traiter à part.

Une échéance est un échec, pas une valeur sentinelle : `null`, `false` et `[]` reviennent intacts
quand le travail se résout à temps.

### Attendre sur une condition

`await()` prend aussi une **condition** — un prédicat sur l'état du workflow lui-même — partout où
elle prend un awaitable, avec la même échéance facultative. C'est ce qu'un gestionnaire de signal
réveille :

```php
$env->onSignal(OrderSignal::Approve, fn(array $p) => $this->approvals[] = $p);

try {
    $env->await(fn(): bool => [] !== $this->approvals, Duration::hours(1));
} catch (DeadlineExceededException) {
    return $this->expire($orderId);
}
```

C'est la forme canonique de la saga : attendre l'approbation, renoncer au bout d'une heure.

Une condition doit être fonction de **l'état du workflow et de rien d'autre**. Elle est réévaluée à
chaque rejeu : tout ce qu'un rejeu ne peut pas reproduire — une horloge, un tirage aléatoire, une
variable d'environnement — doit être enregistré une fois avec `sideEffect()` puis relu :

```php
$threshold = $env->sideEffect(fn(): int => random_int(1, 10));   // enregistré une fois
$env->await(fn(): bool => $this->received >= $threshold);        // se rejoue à l'identique
```

Le composant ne **détecte pas** une condition qui enfreint cette règle ; il ne détecte aucun autre
non-déterminisme non plus, et `sideEffect()` est le mécanisme qu'il vous donne à la place.

> [!WARNING]
> `fn()` capture **par valeur**. Une condition portant sur une variable locale doit passer par la
> forme longue : `function () use (&$approvals): bool { … }`. Sur `$this->propriété`, la forme
> courte convient — c'est `$this` qui est capturé, pas la valeur.

Une condition qui ne peut jamais tenir — rien de ce qui est en attente ne peut changer l'état
qu'elle lit — est signalée comme une exécution qui ne peut plus avancer, en nommant la condition
par son fichier et sa ligne, plutôt que de tourner à vide.

La branche perdante, quelle qu'elle soit, est annulée : une échéance qui s'écoule annule le travail
qu'elle bornait, et un travail qui se résout annule l'échéance, si bien qu'aucun minuteur mort ne
vient réveiller l'exécution plus tard. Annuler une activité en vol est un **effort au mieux** :
Temporal reçoit une *demande* d'annulation, et une tentative qui ne l'honore pas peut continuer de
tourner sur son worker. Ce que l'échéance garantit, c'est que sa complétion ne reprendra plus votre
workflow.

Le verdict est lu depuis l'historique enregistré : un rejeu atteint donc le verdict qu'a atteint
l'exécution d'origine — **y compris** quand le signal attendu est livré après l'échéance écoulée.
Un message enregistré après le déclenchement de l'échéance n'est jamais appliqué à l'attente que
cette échéance a tranchée ; il reste disponible pour l'attente suivante, et son gestionnaire
s'exécute à ce moment-là. Voir **DUR032** et **DUR035**.

### `ActivityOptions` sur le stub

Pour appliquer **réessais**, **délais**, **file de tâches** et métadonnées de planification voisines à tous les appels passant par un stub donné, passez des **`ActivityOptions`** en second argument d'**`activityStub()`** :

```php
use Gplanchat\Durable\Activity\ActivityOptions;

$options = ActivityOptions::of(5, 120);   // 5 tentatives, 120 s chacune
$activities = $this->environment->activityStub(OrderActivities::class, $options);
```

D'autres cas de figure dans [Écrire des activités — ActivityOptions](../activities/#activityoptions-timeouts-retries-task-queue),
et chaque option est décrite dans [Options et objets valeur](../options/).

### Nommage : `ActivityStub` ou `ActivityInvoker`

Les ADR emploient le terme canonique **`ActivityInvoker`** pour ce motif. Dans le paquet actuel, le type s'appelle **`ActivityStub`** et vient de **`WorkflowEnvironment::activityStub()`** — même rôle : des appels typés qui renvoient un **`Awaitable`**. Le stub délègue à un port de planification étroit qu'un workflow ne reçoit jamais — c'est pourquoi désigner une activité par une chaîne n'est pas quelque chose que le code d'un workflow puisse faire.

## Exemple : deux méthodes d'entrée

Si vous exposez **deux** méthodes `#[WorkflowMethod]` sur le même type de workflow, **DUR022** exige qu'**exactement une** porte **`default: true`** sur l'attribut. Quand l'attribut expose ce paramètre dans votre version, cela donne :

```php
#[WorkflowMethod]
public function runMain(Input $input): mixed { /* ... */ }

#[WorkflowMethod(default: true)] // à titre d'illustration — à activer quand l'attribut le prendra en charge
public function runAlternate(Input $input): mixed { /* ... */ }
```

Tant que **`default`** n'existe pas sur **`#[WorkflowMethod]`**, suivez les règles d'enregistrement de votre moteur pour désigner l'entrée principale.

## Ce que vous définissez

1. Une **interface de workflow** (contrat facultatif) et/ou une **classe** portant **`#[Workflow]`** (l'attribut se pose sur la **classe** avec les chargeurs actuels). C'est le contrat typé, pour l'enregistrement et pour les tests.
2. Une **classe concrète** qui **implémente** votre contrat et se déclare au moteur.
3. **Exactement un** paramètre de constructeur sur l'implémentation : **`WorkflowEnvironment $environment`**. N'injectez **pas** de services, de dépôts ni d'autres dépendances applicatives dans la classe de workflow — les effets de bord appartiennent aux [activités](../activities/).

## Registre : alias et nom pleinement qualifié

Quand une classe de workflow est enregistrée, le moteur l'indexe sous **deux** chaînes : le **nom** donné à **`#[Workflow]`** (premier argument), ou le **nom court** de la classe si l'attribut est absent, et le **nom de classe pleinement qualifié** (FQCN). **`WorkflowRegistry::getHandler()`** accepte **l'une ou l'autre** clé pour l'aiguillage.

**Temporal et le journal durable** emploient l'**alias** comme nom de type de workflow, jamais le FQCN. **`WorkflowRunHandler`** et **`TemporalWorkflowStarter`** normalisent les charges utiles de **`WorkflowRunMessage`** avec **`WorkflowDefinitionLoader::aliasForTemporalInterop()`** : si vous passez un FQCN, il est résolu en alias avant que **`ExecutionStarted`** ne soit persisté et avant que le **`WorkflowType`** Temporal ne soit posé. Les métadonnées stockées emploient l'alias, par cohérence avec le serveur.

## Entrée et gestionnaires facultatifs {#entry-and-optional-handlers}

- Déclarez **au moins une** méthode portant **`#[WorkflowMethod]`** — votre entrée durable principale (le démarrage du scénario).
- Si vous exposez **plusieurs** méthodes `#[WorkflowMethod]` sur le même type de workflow, **exactement une** doit porter **`default: true`** pour que le moteur sache laquelle est l'entrée principale.
- Ajoutez éventuellement :
  - **`#[SignalMethod]`** — une entrée externe qui met à jour l'état du workflow de façon déterministe ;
  - **`#[QueryMethod]`** — une vue en lecture seule de l'état (aucun effet de bord durable depuis le gestionnaire) ;
  - **`#[UpdateMethod]`** — des mises à jour validées, avec sémantique de réponse quand elle est prise en charge.

Paramètres et types de retour doivent être **sérialisables** (voir l'ADR de sérialisation **DUR007**).

## `WorkflowEnvironment`

Le moteur injecte **`WorkflowEnvironment`** dans votre constructeur. Voici toute sa surface — tout
ce qu'un workflow peut faire, et rien de ce que le moteur garde pour lui.

| | |
|---|---|
| `await($awaitable, $deadline = null)` | La seule attente. Une échéance écoulée lève `DeadlineExceededException` — un échec, pas une valeur, pour qu'un travail rendant légitimement `null` reste distinguable. |
| `all(...$awaitables)` | Se résout quand tous les membres réussissent. Un seul échec fait tout échouer. |
| `any(...$awaitables)` | Se résout au premier membre qui se résout ; les perdants sont annulés. |
| `some($count, ...$awaitables)` | Se résout quand `$count` membres ont **réussi**, indexés par position de déclaration. Les autres sont annulés. |
| `timer($duration, $summary = '')` | Un awaitable qui se résout à l'échéance de la durée. Se compose comme n'importe quel autre. |
| `sleep($duration, $summary = '')` | Attend, et fait l'attente pour vous. Dit ce qu'il fait. |
| `activityStub($contract, $options = null)` | Un proxy typé sur un contrat d'activité. Construisez-le dans le constructeur ; tous ses appels portent `$options`. |
| `childWorkflowStub($class, $options = null)` | Le même, pour un workflow enfant : résolu depuis la classe de l'enfant, et ses appels se composent comme les autres. |
| `waitSignal($name, $deadline = null)` | Attend un signal. Le nom prend une énumération adossée, donc une faute de frappe est une erreur de type et non une attente qui ne se résout jamais. |
| `waitUpdate($name)` | Attend une mise à jour. |
| `sideEffect($closure)` | Exécute une fois un travail local non déterministe et en journalise le résultat, pour que le rejeu le reproduise. |
| `continueAsNew($type, $payload = [], $options = null)` | Termine cette exécution et démarre la suivante avec un historique neuf. |
| `executionId()` | L'identifiant de cette exécution. |

Les activités ne sont joignables **qu'**à travers un stub. En désigner une par une chaîne, avec une
charge utile libre, ne figure pas sur cette surface : une faute de frappe y produirait une activité
jamais planifiée, au lieu d'une erreur que votre IDE et votre analyseur statique attrapent d'abord.

Les gestionnaires de requête, de signal et de mise à jour se déclarent par `#[QueryMethod]`,
`#[SignalMethod]` et `#[UpdateMethod]`, et le moteur les câble. Signaux et mises à jour peuvent
aussi s'enregistrer de façon impérative — `onSignal()`, `onUpdate()` — ce qu'un workflow exprimé
sous forme de fermeture est obligé d'employer, une fermeture ne pouvant pas porter d'attribut.
Préférez l'attribut : c'est la forme qu'un lecteur voit sans rien exécuter.

**Les requêtes n'ont pas de forme impérative.** Elles sont lues par le worker, hors de la fibre du
workflow : leurs gestionnaires vivent côté moteur et `#[QueryMethod]` est le seul moyen d'en
déclarer un. Un workflow en forme de fermeture ne peut pas répondre à une requête ; s'il doit le
faire, il doit être une classe.

Vous n'instanciez jamais d'implémentation d'activité dans le corps du workflow.

## Aide-mémoire

| Règle | Détail |
|-------|--------|
| Constructeur | `WorkflowEnvironment` et rien d'autre |
| Contrat | Interface + `#[Workflow]` ; la classe l'implémente |
| Entrée | Au moins une `#[WorkflowMethod]` ; `default: true` s'il y en a plusieurs |
| E/S | Aucune dans le workflow — passez par des activités |
| Appels au travail | Par un **`ActivityStub`**, construit dans le constructeur depuis un contrat d'activité |

## Voir aussi

- [Concepts](../concepts/) — workflow contre activité, rejeu, backends.
- [Écrire des activités](../activities/) — interfaces d'activité, `#[ActivityMethod]`, et **`ActivityInvoker`**.
