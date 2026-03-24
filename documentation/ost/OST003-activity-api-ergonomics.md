# OST003 — Ergonomie des appels d’activités (style classes / attributs)

## Contexte

L’API actuelle :

```php
$ctx->activity('greet', ['name' => 'Durable']);
```

est correcte pour le moteur (nom + tableau JSON-sérialisable dans l’event store), mais peu lisible côté workflow : pas de typage du contrat, pas d’auto-complétion, noms en chaînes « magiques ».

L’objectif est de s’inspirer de l’esprit **Temporal** (contrats explicites, classes dédiées, métadonnées déclaratives) tout en restant compatibles avec **PHP 8.2+**, le **journal d’événements** et les **transports** existants.

### Décision : `activity()` vit dans le **moteur**

La **planification** d’une activité (construction de l’`Awaitable`, émission vers le journal / event store, nom + payload sérialisable) doit être implémentée **une seule fois**, dans la **couche moteur** (service dédié, `ExecutionEngine`, adaptateur interne — le nom exact est à figer en ADR), et non répétée dans le code utilisateur des workflows.

- **`ExecutionContext::activity(string, array)`** (tel qu’exposé aujourd’hui) devient au minimum une **façade** vers ce cœur, ou est **réduit** à un point d’entrée bas niveau / interne selon le plan de migration.
- Les ergonomies **stub** (`activityStub` / `ActivityStub<T>`), **DTO** (`invoke`), etc. **délèguent toutes** vers la **même** implémentation moteur — pas de second chemin parallèle.

## Contraintes à préserver

- Le **payload persisté** reste un **tableau JSON** (ou structure dérivée strictement sérialisable) dans `ActivityScheduled` / `ActivityMessage`.
- Le **nom logique** d’activité reste une **chaîne** stable dans le temps (versioning = autre nom ou champ `schemaVersion` dans le DTO).
- **Rétrocompatibilité** : les workflows existants peuvent continuer à appeler `$ctx->activity(...)` tant que cette méthode **redirige** vers le moteur ; la **DX cible** est **`activityStub(Contrat::class)`** (une ligne) puis des appels de méthodes typés — **pas** de classe wrapper écrite à la main par l’utilisateur.

### Attributs **`#[Workflow]`** et **`#[Activity]`** (classe) — **noms stables si la classe PHP est renommée**

Le **nom logique** enregistré dans le journal / auprès du worker ne doit **pas** dépendre uniquement du **nom de classe PHP** : un refactor (`OrderWorkflow` → `ProcessOrderWorkflow`, `OrderActivitiesInterface` → `CommerceActivitiesInterface`) ne doit pas **casser** l’identité du workflow ou du contrat d’activités dans l’historique.

**À prévoir** (à figer en ADR : cible `Attribute::TARGET_CLASS`, éventuellement `TARGET_INTERFACE`) :

| Attribut | Portée | Rôle |
|----------|--------|------|
| **`#[Workflow('nom_logique')]`** | Classe (ou interface) du **handler workflow** | Identifiant **stable** du type de workflow côté moteur / event store, **indépendant** du FQCN PHP après renommage. |
| **`#[Activity('nom_logique')]`** | **Contrat** d’activités (interface ou classe d’impl worker), **ou** DTO d’appel (piste A) | Identifiant **stable** du **regroupement** ou du **type** d’activité selon le modèle retenu ; complète **`#[ActivityMethod]`** sur les méthodes (piste C). |

**Piste C** : placer **`#[Activity('…')]`** sur **`OrderActivitiesInterface`** (et idéalement répéter la même valeur sur la classe concrète worker pour cohérence) ; les **`#[ActivityMethod('reserve_stock')]`** restent les noms **d’activité élémentaires**. L’ADR définit comment le moteur **compose** ou **sépare** `Activity` + `ActivityMethod` (préfixe `nom_logique.` + méthode, clés de cache PSR-6, clé de registry, etc.).

**Piste A** : le **`#[Activity('greet')]`** sur le **DTO readonly** fixe déjà le **nom d’activité** de l’appel ; renommer la classe `GreetInput` sans changer la chaîne **`'greet'`** préserve la compatibilité historique.

**Outils** : warmers, plugins **PHPStan / Psalm** et contrôles de cohérence doivent lire ces attributs **au niveau classe** en plus des méthodes.

---

## Piste A — Attribut `#[Activity]` + DTO d’entrée (recommandée)

Une **classe readonly** représente l’appel ; un attribut PHP 8 porte le **nom d’activité** enregistré côté worker (voir aussi *noms stables* ci-dessus).

```php
#[Activity('greet')]
final readonly class GreetInput
{
    public function __construct(public string $name) {}
}
```

Même attribut **`Activity`** que sur le **contrat** d’activités (piste C), ici sur une **classe** DTO : la chaîne **`'greet'`** est l’identifiant **stable** dans le journal ; renommer `GreetInput` ne casse pas l’historique tant que **`'greet'`** est inchangé.

API workflow cible :

```php
await($ctx->invoke(new GreetInput('Durable')), $ctx, $rt);
// ou alias lisible :
await($ctx->runActivity(new GreetInput('Durable')), $ctx, $rt);
```

Mise en œuvre (conceptuelle) :

1. Interface `ActivityInvocation` (ou trait) : `activityName(): string`, `toActivityPayload(): array`.
2. **`#[Activity('greet')]`** : métadonnées résolues **hors chemin chaud** — même principe que la piste C : **cache PSR-6** alimenté à la **chauffe** (voir section *Cache métadonnées : PSR-6 et chauffe uniquement*) ; en dev, un **miss** peut déclencher une résolution ponctuelle puis écriture dans le pool.
3. `ExecutionContext::invoke(object $input): Awaitable` :
   - résout le nom depuis l’attribut sur la classe de `$input` ;
   - appelle `toActivityPayload()` ( défaut : `get_object_vars` / normaliseur dédié ) ;
   - **délègue au moteur** (même primitive que `activityStub` / `activity()` interne).

**Variante nom implicite** : si l’attribut omet le nom, dériver `greet` depuis `GreetInput` → `greet` (camelCase / snake_case documenté).

**Avantages** : proche de Temporal, typage fort, une classe = un contrat.  
**Inconvénients** : besoin de **chauffe / cache** pour éviter la réflexion en runtime ; convention de sérialisation à figer (voir ADR006).

---

## Piste B — Enum typé pour le nom + payload séparé

Pour limiter la réflexion au strict minimum :

```php
enum ActivityName: string
{
    case Greet = 'greet';
    case ChargeCard = 'charge_card';
}

await($ctx->activityNamed(ActivityName::Greet, new GreetPayload('Durable')), ...);
```

`GreetPayload` reste un readonly DTO avec `toArray()` explicite.

**Avantages** : noms d’activités **exhaustifs** pour les `switch` / PHPStan.  
**Inconvénients** : deux artefacts (enum + DTO) par activité si le payload n’est pas vide.

---

## Piste C — Contrat d’activité + **`activityStub()` moteur** — **retenue**

### Syntaxe workflow cible (simple, sans code utilisateur de proxy)

L’utilisateur **ne crée pas** de classe « Activities » ni de méthodes qui enrobent `activity()`. Il déclare **uniquement** le **contrat** (interface + implémentation worker) avec **`#[Activity('…')]`** sur le type (nom stable) et **`#[ActivityMethod]`** sur les méthodes métier ; le moteur fournit le proxy en **une** expression.

```php
// Une fois par workflow (ou par bloc logique) : le moteur construit le proxy.
/** @var ActivityStub<OrderActivitiesInterface> $orders */
$orders = $env->activityStub(OrderActivitiesInterface::class);

// Appels naturels : typage + auto-complétion ; le moteur planifie (Awaitable<string>, etc.).
$reserved = $env->await($orders->reserveStock('SKU-1', 3));
```


**Ce que l’utilisateur écrit** : interface d’activité, classe concrète worker, puis **`activityStub`** + appels de méthodes. **Ce qu’il n’écrit pas** : aucune classe intermédiaire qui duplique les noms d’activité ou appelle `$env->activity(string)` à la main.

### Une classe concrète + interface (contrat uniquement)

Il n’y a **pas** deux implémentations concrètes de la même activité : **une seule classe** porte le code métier et est **exécutée sur le worker**.

| Élément | Rôle |
|---------|------|
| **Interface** (ex. `OrderActivitiesInterface`) | **Alignée** sur la classe d’activité : signatures communes avec l’impl worker ; la classe concrète **`implements`** cette interface. **`#[Activity('nom_stable')]`** sur le type (interface + impl) : identifiant **stable** si le symbole PHP est renommé. Seules les méthodes **`#[ActivityMethod]`** sont planifiables via le stub ; autres méthodes sans `ActivityMethod` : pas d’exposition workflow. |
| **Proxy moteur** (`ActivityStub<…>`) | Fourni par **`$env->activityStub(...)`** : seul endroit où le workflow voit des **`Awaitable<R>`**. **Uniquement les méthodes annotées `#[ActivityMethod]`** sont exposées : mêmes noms / paramètres que sur le contrat pour ces seules méthodes, avec retours suspendus `Awaitable<R>` (analyse statique / doc). |

Côté **workflow** (replay), le proxy **émet** des `Awaitable` vers le journal — pas d’exécution du corps métier. Côté **worker**, ce sont les méthodes de la **classe concrète** qui s’exécutent.

### Rôle des attributs `#[Activity]` (classe) et `#[ActivityMethod]` (méthode)

En **Temporal** (Java, .NET, PHP SDK officiel), les workflows appellent des **stubs** générés ou annotés : le nom d’activité côté serveur est une donnée de **contrat**, pas une chaîne éparpillée dans le corps de la méthode.

**`#[Activity('…')]`** sur le **contrat** : ancrage **stable** pour le moteur, le cache PSR-6 et le registry quand le **nom de classe ou d’interface PHP** évolue (refactor). Doit être **cohérent** entre interface et implémentation worker (vérification au warmup ou en test).

**`#[ActivityMethod]`** sur **chaque méthode** du contrat qui doit être une activité (interface ou classe partagée avec le worker) sert à :

1. **Délimiter la surface du stub** : le proxy retourné par `activityStub()` **n’expose que** les méthodes ainsi annotées. Toute autre méthode du type `TActivity` (sans `#[ActivityMethod]`) **n’est pas** invocable sur le stub — évite d’étendre par erreur le contrat workflow à des helpers ou de l’API purement worker.
2. **Fixer le nom logique d’activité** **élémentaire** enregistré dans le worker (`RegistryActivityExecutor::register('reserve_stock', …)`, etc.) — **source de vérité** lisible à la compilation / analyse statique ; la composition éventuelle avec **`#[Activity]`** (classe) est définie en ADR (nom complet, préfixe, clé de cache).
3. **Permettre la vérification** : outil ou test qui parcourt les méthodes **annotées** et vérifie que chaque `#[ActivityMethod('…')]` a bien un handler enregistré sous le même nom.
4. **Éviter la dérive** : renommer la méthode PHP `greetCustomer()` ne change pas le nom d’activité côté historique ; seul le paramètre de l’attribut (ou un nom explicite dedans) reste stable pour le journal.
5. **Évolution** : champs optionnels sur l’attribut pourront porter plus tard des **métadonnées** (ex. `taskQueue`, politique de retry côté activité, *timeouts* — quand le moteur les exposera), sans changer la signature vue par le workflow.

**Forme envisagée** (à figer en ADR — **noms de classes PHP** à caler dans un namespace dédié, ex. `…\Attribute\`, pour éviter les collisions avec le domaine métier) :

```php
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_INTERFACE)]
final class Activity
{
    public function __construct(
        public string $name,
        // futur : métadonnées communes au contrat
    ) {}
}

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_INTERFACE)]
final class Workflow
{
    public function __construct(
        public string $name,
    ) {}
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class ActivityMethod
{
    public function __construct(
        public string $name,
        // futur : ?string $taskQueue = null, ...
    ) {}
}
```

**Exemple** — **interface alignée** sur l’activité ; **`Awaitable` uniquement via `activityStub()`** (pas dans l’interface) ; **noms stables** si renommage PHP :

```php
/** Contrat d’activité : identique à ce que la classe concrète expose côté worker. */
#[Activity('order_activities')]
interface OrderActivitiesInterface
{
    #[ActivityMethod('reserve_stock')]
    public function reserveStock(string $sku, int $quantity): string;
}

/** Une seule classe d’activité — enregistrée sur le worker, corps métier ici. */
#[Activity('order_activities')]
final class AcmeOrderActivities implements OrderActivitiesInterface
{
    #[ActivityMethod('reserve_stock')]
    public function reserveStock(string $sku, int $quantity): string
    {
        // code métier réel, exécuté uniquement sur le worker
    }
}
```

**Exemple workflow** (handler — nom de type stable même si la classe PHP est renommée) :

```php
#[Workflow('process_order')]
final class ProcessOrderWorkflow
{
    // …
}
```

*Une méthode sur le même contrat **sans** `#[ActivityMethod]` peut exister pour l’impl worker (ou pour d’autres usages) : elle **n’apparaît pas** sur `ActivityStub<…>` et ne doit **pas** être appelée depuis le code workflow via le proxy.*

Côté **workflow**, on n’utilise pas l’interface telle quelle pour les retours : on obtient un **`ActivityStub<OrderActivitiesInterface>`** (voir ci-dessous) qui **ne projette que les méthodes `#[ActivityMethod]`** : pour celles-ci, **mêmes paramètres** que sur le contrat mais retours **conceptuellement** `Awaitable<string>`, `Awaitable<Product>`, etc.

### Modèle mental : `ActivityStub<TActivity>` et `Awaitable<R>`

Le **stub** est paramétré par la classe ou l’interface d’activité (`TActivity`). Sa **signature statique** ne couvre **que** les méthodes de `TActivity` portant **`#[ActivityMethod]`** ; pour chacune, **mêmes paramètres** que sur le contrat, **types de retour** remplacés par `R` → **`Awaitable<R>`** (ex. `string` → `Awaitable<string>`, `Product` → `Awaitable<Product>`). Les méthodes sans attribut sont **absentes** du stub (comportement à l’appel : erreur explicite ou méthode inexistante selon implémentation — à figer en ADR).

En PHP, cela ne peut pas être exprimé entièrement par le langage seul. **À prévoir** : des **plugins dédiés PHPStan et Psalm** (voir section suivante) ; en complément ou alternative, **code généré** ou annotations `@var` manuelles.

```php
/**
 * Proxy de planification côté workflow.
 * Modèle mental : sous-ensemble de TActivity — uniquement les méthodes #[ActivityMethod],
 * retours remplacés par Awaitable<ReturnType>.
 *
 * @template TActivity of object
 */
final class ActivityStub
{
    /**
     * @param class-string<TActivity> $activityClass
     */
    public function __construct(
        private readonly string $activityClass,
        private readonly ExecutionContext $context,
    ) {
    }

    // À l’exécution : __call lit les métadonnées depuis le cache PSR-6 (pas de réflexion).
}
```

### Cache métadonnées : **PSR-6** et **chauffe** uniquement

**Décision** : la **réflexion** (`ReflectionClass` / `ReflectionMethod`, lecture des attributs) ne doit servir qu’à **alimenter un cache**, et **idéalement uniquement pendant une phase de chauffe** (build, `cache:warmup`, compile Symfony), **pas** sur le chemin chaud d’un workflow en production.

- **Stockage** : **`Psr\Cache\CacheItemPoolInterface`** (PSR-6). Symfony expose des **pools** configurables (`framework.cache`) : même abstraction côté bundle Durable / Kiboko, intégration naturelle avec `cache:warmup` et les **cache warmers** (`Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface`).
- **Contenu typique d’une entrée** (par `class-string` de contrat d’activité, voire par couple `(classe, nom de méthode)`) : **`#[Activity]`** résolu sur le type (nom stable du contrat), liste des méthodes **`#[ActivityMethod]`**, **nom d’activité** logique par méthode, **noms des paramètres** (→ clés payload), éventuellement type de retour documenté pour l’analyse statique.
- **Runtime** (`ActivityStub`, `invoke(DTO)`, etc.) : **lecture seule** dans le pool PSR-6 ; si **miss** en prod → politique à figer en ADR (échec explicite vs chauffe obligatoire au déploiement).

**Réalisations possibles** (souvent combinées) :

1. **Génération de code** (alternative ou complément) : produire une classe intermédiaire avec des `@method` / signatures explicites **pour les seules méthodes `#[ActivityMethod]`** — peut éviter tout pool à l’exécution si tout est figé en codegen.
2. **Plugins PHPStan + Psalm** (voir *Analyse statique* ci-dessous) — **livrabilité prévue** du produit.
3. **Dégradé dev** : miss PSR-6 → une passe réflexion **ponctuelle** + `save()` dans le pool (jamais la cible prod sans chauffe explicite).

### Analyse statique : **plugins PHPStan et Psalm** — **à prévoir**

Le proxy **`ActivityStub`** s’appuie sur **`__call`** (ou équivalent) : sans extension, l’analyse statique ne peut pas inférer **`Awaitable<R>`** par méthode. Il faut donc prévoir **deux** extensions maintenues dans l’écosystème du package (ou du monorepo).

#### Distribution **Packagist** : composants **indépendants** et **`suggest`**

- **Deux packages Composer distincts** publiés sur **Packagist**, **sans** les déclarer en **`require`** de la bibliothèque principale (`gplanchat/durable` ou équivalent) : l’utilisateur n’embarque **ni PHPStan ni Psalm** s’il n’en a pas besoin.
- **Installation à la carte** : `composer require --dev gplanchat/durable-phpstan` et/ou `composer require --dev gplanchat/durable-psalm-plugin` (noms indicatifs — à figer à la publication).
- Le **`composer.json`** du package **durable** (cœur) doit déclarer ces deux noms dans **`"suggest"`** avec une courte description : à chaque `composer install` / `update`, Composer **affiche** la suggestion sans l’installer — incitation claire et conforme aux usages ecosystem.
- **Dépendances** : chaque plugin liste en **`require`** (ou `require-dev` selon choix) la **version minimale** de **`gplanchat/durable`** avec laquelle il est compatible ; le cœur ne dépend pas des plugins.

| Livrable Packagist | Rôle côté projet utilisateur |
|--------------------|------------------------------|
| **`gplanchat/durable-phpstan`** (nom indicatif) | `composer require --dev` ; **`includes`** dans `phpstan.neon` / `phpstan.dist.neon`. |
| **`gplanchat/durable-psalm-plugin`** (nom indicatif) | `composer require --dev` ; **`psalm.xml`** (`<plugins><plugin class="…"/></plugins>`). |

**Comportements cibles** (à figer en ADR / specs des plugins) :

1. **`activityStub(Class::class)`** : inférer un type **`ActivityStub<TContract>`** avec **`TContract`** = classe ou interface passée ; vérifier la présence de **`#[Activity]`** sur le contrat ; seules les méthodes portant **`#[ActivityMethod]`** sont considérées comme existantes sur le stub.
2. **Appel `$stub->foo(...args)`** : type de retour **`Awaitable<R>`** où **`R`** est le type de retour **métier** déclaré sur **`TContract::foo`** (support **génériques** sur `Awaitable` via docblocks `@template` / équivalent Psalm).
3. **Méthode sans `#[ActivityMethod]`** : **erreur** (ou niveau strict configurable) si le workflow tente de l’appeler sur le stub — aligné sur le runtime.
4. **Piste A (`invoke`)** : même attribut **`#[Activity]`** sur **DTO** (nom d’activité stable pour l’appel) ; règle complémentaire pour inférer `Awaitable`.
5. **`#[Workflow]`** : sur la classe handler, pour cohérence d’analyse avec le **type de workflow** stable (enregistrement / journal).

**Source de vérité pour l’analyse** : lecture des **attributs natifs PHP** sur le contrat (`Reflection*`) **pendant l’analyse** — ce n’est **pas** le chemin chaud du workflow ; alternative ou complément : lecture d’un **dump** généré au **warmup** (même schéma que les entrées **PSR-6**) pour accélérer CI ou partager un seul artefact entre warmer et analyseurs.

**Documentation utilisateur** : sections README + exemples `phpstan.neon` / `psalm.xml` pour les projets Symfony / standalone.

La fabrique du moteur (ex. sur `ExecutionContext`) peut être documentée ainsi :

```php
// Exemple de signature documentée (le nom exact de la méthode est à figer en ADR)
/**
 * @template TActivity of object
 * @param class-string<TActivity> $activityClass
 * @return ActivityStub<TActivity>
 */
public function activityStub(string $activityClass): ActivityStub { /* … */ }
```

*Si l’on préfère une seule **classe concrète** sans interface dédiée, `TActivity` peut être cette classe (les méthodes `#[ActivityMethod]` sont alors sur la classe elle-même) ; une **`…Interface`** reste recommandée pour tests doubles et séparation contrat / impl.*

**Hors périmètre DX** : classes wrapper écrites à la main, `invokeFromCurrentMethod` dans le code applicatif, ou toute forme où l’utilisateur ré-implémente la planification. Le **seul** chemin ergonomique est **`activityStub()`** (éventuellement complété par **`invoke(DTO)`** piste A pour des appels ponctuels sans contrat multi-méthodes).

### `activity()` dans le moteur — **retenu**

**Direction validée** : la primitive **`scheduleActivity(name, payload)`** (ou équivalent) est **implémentée dans le moteur** ; `ExecutionContext` **route** vers elle. Côté workflow, la syntaxe **simple** est **`activityStub(TActivity::class)`** puis appels de méthodes (ou **`invoke(DTO)`** piste A) — **pas** de wrappers maison autour de `$ctx->activity(...)`.

**Principe opérationnel** : exposer une fabrique du type `ExecutionContext::activityStub(string $activityClass): ActivityStub` (voir génériques ci-dessus) qui retourne un **proxy** `ActivityStub<TActivity>`. Chaque appel de méthode sur ce proxy :

1. **résout les métadonnées depuis le cache PSR-6** pour `(TActivity, nomDeMéthode)` ; absence d’entrée ou méthode non enregistrée → **refus** (pas d’activité implicite pour les méthodes « ordinaires » du contrat) ;
2. obtient le **nom d’activité** et les **noms de paramètres** depuis cette entrée (remplis à la **chauffe**, via réflexion à ce moment-là uniquement) ;
3. construit le **payload** à partir des arguments (convention : **noms des paramètres** → clés du tableau associatif) ;
4. retourne un **`Awaitable`** dont le **type métier** correspond au type de retour attendu pour cette méthode (documenté / inféré hors chemin chaud) ;
5. appelle **la primitive moteur** (unique) — *pas* une copie de la logique dans le code utilisateur.

Exemple d’usage côté workflow (**sans** `activity()` dans le code applicatif — **uniquement** le proxy / moteur) :

```php
/** @var ActivityStub<OrderActivitiesInterface> $orders */
$orders = $ctx->activityStub(OrderActivitiesInterface::class);
await($orders->reserveStock('SKU-1', 3), $ctx, $rt);
```

`OrderActivitiesInterface` est le **contrat** : retours **métier** (`string`, etc.) sur les méthodes annotées. Le **stub** est le seul niveau où le workflow voit des **`Awaitable<…>`**, et **seulement** pour ces méthodes-là. La classe concrète enregistrée sur le worker **implémente** l’interface ; le moteur résout par nom d’activité / registry.

### Masquer `$ctx` et `$rt` dans le stub

Aujourd’hui `await($awaitable, $ctx, $rt)` impose de **répéter** le contexte et le runtime à chaque suspension. Côté workflow, on peut **lier** une fois `$ctx` + `$rt` et n’exposer qu’une API de type « exécuter cet awaitable ».

**Pistes de conception** (à figer en ADR / API) :

1. **Stub « lié »** : `activityStub(..., $ctx, $rt)` ou `->bindRuntime($rt)` après construction ; le stub expose `await(Awaitable $a): mixed` (ou `run`) qui appelle en interne `$runtime->await($a, $context)` — même sémantique que `Gplanchat\Durable\await()`, sans répéter les deux premiers arguments.
2. **Facade workflow** : petit objet `WorkflowHandle` / `WorkflowAwait` créé une fois en tête de handler (`WorkflowAwait::for($ctx, $rt)`) ; `->await($orders->reserveStock('SKU-1', 3))` encapsule le trio (exemple aligné sur `OrderActivitiesInterface`).
3. **Awaitable enrichi** (optionnel) : type `BoundAwaitable` produit par le stub qui retient ctx+rt — attention aux **replays** et à l’identité du contexte : le binding doit rester cohérent avec l’`ExecutionContext` courant du workflow (généralement le même que celui passé au handler).

**Contrainte** : les helpers `parallel` / `any` / `delay` du module `functions.php` prennent aussi `($ctx, $rt)` ; une façade unifiée peut les réexposer en méthodes pour éviter le mélange « parfois global await, parfois méthode ».

Même flux qu’en *Syntaxe workflow cible* (ci-dessus), avec liaison unique de `$ctx` / `$rt` :

```php
$w = $ctx->workflowAwait($rt);
$orders = $ctx->activityStub(OrderActivitiesInterface::class);
$sku = $w->await($orders->reserveStock('SKU-1', 3));
```

**À prévoir** : **pool PSR-6** injecté dans le moteur / contexte ; **warmer** Symfony (ou équivalent) qui parcourt les contrats d’activité connus et écrit les entrées incluant **`#[Activity]`** résolu + `(classe, méthode) → { activityMethodName, paramNames }` **pour les méthodes `#[ActivityMethod]`** ; vérification **interface / impl** : même chaîne **`#[Activity]`** ; **extensions PHPStan + plugin Psalm** (voir *Analyse statique*) ; évolutions futures (clés payload ≠ noms de paramètres) via attribut optionnel sur paramètres ou DTO unique ; **refactor** de `ExecutionContext::activity()` pour qu’il ne soit qu’un **adaptateur** vers le composant moteur qui porte la vraie logique.

**Inconvénient** : dépendance au cycle de **chauffe** / déploiement pour un cache à jour ; **avantage** : **zéro réflexion** sur le chemin chaud du workflow, **alignement Symfony** (`cache.pool`, warmers), **un seul endroit** pour la planification (journal, IDs, sérialisation).

### Discipline de code

- **Interface d’activité** (ex. `OrderActivitiesInterface`) : **alignée** sur la classe concrète (`implements`) ; retours **métier** — pas de `Awaitable` dans l’interface.
- **`#[Activity('…')]`** (classe / interface) : **nom stable** du contrat (ou du DTO piste A) — refactor PHP **sans** changer l’identité côté journal / registry si la chaîne est conservée.
- **`#[Workflow('…')]`** (classe / interface handler) : **nom stable** du type de workflow, indépendant du renommage de la classe PHP.
- **`#[ActivityMethod]`** : marque **explicitement** qu’une méthode est une activité planifiable **et** qu’elle apparaît sur le **stub** ; une méthode sans attribut n’est **pas** exposée au workflow via `activityStub()`.
- **Stub** (`ActivityStub<TActivity>` / proxy) : seul niveau où le workflow manipule des **`Awaitable<R>`**, et **uniquement** pour les méthodes annotées (`R` = type de retour déclaré sur le contrat pour chacune).
- **Classe concrète** : code exécuté sur le worker ; mêmes méthodes annotées + enregistrement aligné sur les noms d’activité.
- **Nom de méthode PHP** = vocabulaire métier pour l’IDE ; **nom dans `#[ActivityMethod]`** = identifiant stable dans l’event store.
- **Cache** : métadonnées des activités dans un **pool PSR-6** ; **réflexion** réservée au **warmup** (ou fallback dev), pas au replay / exécution workflow.
- **Analyse statique** : packages **Packagist indépendants** (PHPStan + Psalm) ; le cœur les **suggère** via Composer sans les imposer — l’utilisateur installe **à la carte** en `require-dev`.

**Avantages** : syntaxe **courte** côté workflow (`activityStub` + méthodes) ; excellente DX dans l’IDE (avec plugins) ; contrat centralisé ; extensible via l’attribut ; **planification uniquement dans le moteur**.  
**Inconvénients** : le moteur doit fournir un proxy fiable + **PSR-6 / warmers** + **maintenance de deux extensions d’analyse** (ou codegen à la place) — coût d’implémentation interne, pas de coût « classes stub » pour l’utilisateur.

---

## Piste D — Builder / named constructors

Sans attributs, lisibilité immédiate :

```php
await($ctx->activity(Greet::withName('Durable')), ...);
```

où `Greet::withName` retourne un petit objet `ActivityCall` `(name: 'greet', payload: [...])` et `activity()` accepte `ActivityCall|string`.

**Avantages** : zéro réflexion, simple.  
**Inconvénients** : moins « standard Temporal » ; le lien classe ↔ nom d’activité est dans la fabrique, pas sur le type payload seul.

---

## Piste E — Symfony Serializer / Normalizer (stack Symfony)

Pour des payloads riches (objets imbriqués, dates) :

- Attribut `#[Activity]` sur la classe d’entrée.
- Sérialisation via **`symfony/serializer`** (déjà dans l’écosystème) vers `array` pour le journal.

À documenter dans une évolution d’**ADR006** si cette piste est retenue.

---

## Synthèse

| Piste | Lisibilité | Typage | Effort moteur | Alignement Temporal |
|-------|------------|--------|---------------|---------------------|
| A — `#[Activity]` + DTO | ★★★★ | ★★★★ | Moyen | ★★★★★ |
| B — Enum + DTO | ★★★ | ★★★★★ | Faible | ★★★ |
| C — Contrat + `activityStub` | ★★★★★ | ★★★★ | Moyen à élevé | ★★★★ |
| D — Builder / `ActivityCall` | ★★★★ | ★★★ | Faible | ★★ |
| E — + Serializer | ★★★★ | ★★★★★ | Élevé | ★★★★ |

**Choix actuel** : **piste C** — contrat d’activité + **`#[Activity]`** / **`#[Workflow]`** (noms stables) + **`#[ActivityMethod]`** + **`activityStub()`** fourni par le moteur (syntaxe workflow simple, **sans** stub manuel utilisateur) ; **`activity()`** implémentée dans le moteur (primitive unique).

**Prochaine étape** : ADR dédié — définition des attributs **`Activity`**, **`Workflow`**, **`ActivityMethod`** (cibles, composition nom contrat + nom méthode), **extraire / concentrer** la logique actuelle de `ExecutionContext::activity()` dans le moteur, implémentation **`activityStub()`** + **`ActivityStub<TActivity>`** (lecture métadonnées via **PSR-6**, réflexion **réservée au cache warmer**), projection **uniquement** des méthodes `#[ActivityMethod]`, **publication Packagist** de **`gplanchat/durable-phpstan`** et **`gplanchat/durable-psalm-plugin`**, specs plugins : `activityStub`, **`#[Activity]`**, **`#[Workflow]`**, `Awaitable<R>`, **interface** (ex. `OrderActivitiesInterface`) **alignée** sur la classe concrète, API **`workflowAwait` / stub lié**, warmers Symfony + tests de cohérence proxy ↔ `RegistryActivityExecutor`.

## Références

- [OST004 — Parité Temporal (side effects, timers, child, CAN, messages)](OST004-workflow-temporal-feature-parity.md)
- [ADR006 — Patterns activité](../adr/ADR006-activity-patterns.md)
- [PRD001 — État actuel](../prd/PRD001-current-component-state.md)
- [ExecutionContext](../../src/ExecutionContext.php) — façade `activity()` (cible : délégation moteur)
- [PSR-6 — Caching Interface](https://www.php-fig.org/psr/psr-6/) — pool injectable, aligné `symfony/cache`
- [PHPStan — Developing extensions](https://phpstan.org/developing-extensions/extension-types) — règles / services d’inférence de types
- [Psalm — Plugins](https://psalm.dev/docs/running_psalm/plugins/) — enregistrement `PluginEntryPointInterface`
