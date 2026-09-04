# Audit Durable — cœur, Symfony, Sylius

20 grilles de relecture indépendantes, chacune portant sur un axe distinct — un domaine
d'expertise du monde Symfony, Sylius ou API Platform. Chaque constat est ancré sur un
`fichier:ligne` vérifié et, quand la règle vient de l'amont, sur la source amont correspondante.
Les vingt rapports détaillés sont dans ce répertoire, un fichier par axe.

Périmètre : `src/Durable` (207 f., 16 300 l.), `src/DurableBundle` (23 f., 3 600 l.),
`src/DurablePlugin` (9 f., 550 l.), `src/Bridge/Dbal`, la partie écrite à la main de
`src/Bridge/Temporal` (94 f. sur 774 — les 680 fichiers protobuf générés sont exclus), la
documentation et les deux bancs d'essai. 151 constats bruts, dédupliqués ci-dessous.

---

## Verdict

Le projet est **solide là où on l'attendrait fragile, et fragile là où on l'attendrait solide.**

Ce qui tient : la désérialisation du journal (aucun `unserialize`, liste blanche `match` fermée —
un journal falsifié n'instancie rien d'arbitraire), l'immuabilité (69 `readonly class`, 29/29
événements `final readonly`, zéro setter), la matrice CI (6.4 LTS × 7.4 LTS × plancher et tête de la
ligne 8, en `lowest` et `highest`), le plancher PHP 8.2 réellement tenu, la procédure de migration
Rector, et l'exactitude de la documentation au niveau des exemples (51 FQCN cités, tous résolvent).

Ce qui ne tient pas se concentre sur **quatre lignes de faille** :

1. **Le rejeu déterministe a un trou et un coût.** Un `sideEffect()` qui rend `null` est ré-exécuté
   à chaque passe — la garantie même que la primitive existe pour offrir. Et toute passe de rejeu
   est quadratique.
2. **Le bundle est un prototype de câblage.** 48 services publics sur 60, 819 lignes d'extension
   procédurale, le profileur actif en production, des middlewares imposés à tous les bus de
   l'application, et deux ponts référencés en dur sans être déclarés nulle part.
3. **Le journal DBAL est invisible de Doctrine.** `doctrine:migrations:diff` génère des
   `DROP TABLE` sur les tables du journal.
4. **Le plugin Sylius est un bundle Symfony qui porte le nom « plugin ».** Ni `type: sylius-plugin`,
   ni dépendance Sylius déclarée, ni traduction, ni préfixe admin configurable.

Trois constats sont contredits par les faits et méritent d'être notés comme tels : il n'y a **pas**
de faille de désérialisation, **pas** de collision avec `symfony/workflow`, et la route admin en dur
n'est **pas** un trou d'authentification (`IsGranted` refuse toujours) — c'est un défaut de
portabilité.

---

## Bloquants

### B1 — `sideEffect()` se rejoue pour de vrai quand la valeur enregistrée est `null`
`src/Durable/ExecutionContext.php:316` · `src/Durable/Port/WorkflowHistorySourceInterface.php:86`
**Deux grilles indépendantes (Fibers/rejeu, contrats) y arrivent par des chemins différents.**

`findSideEffectForSlot(): mixed` rend `null` aussi bien pour « aucun slot enregistré » que pour
« slot enregistré valant `null` ». Le consommateur teste `null !== $replayResult` : il conclut « non
enregistré », **ré-exécute la closure non déterministe** et appende un `SideEffectRecorded` de plus.
À chaque passe : effet de bord rejoué, valeur potentiellement différente, journal qui croît d'un
événement par replay. Les trois méthodes sœurs du même port (`findActivitySlotResult`,
`findChildWorkflowForSlot`, `findNexusOperationSlotResult`) enveloppent justement leur résultat dans
une forme `array{result: mixed, …}` pour éviter exactement cela — l'incohérence est interne au
contrat. Temporal encode la présence d'un side effect par l'existence du marqueur, jamais par sa
valeur.

*Correctif* : `findSideEffectForSlot(int $slot): ?array` de forme `array{result: mixed}|null`, ou un
`hasSideEffectForSlot(int): bool` au port. La présence d'un slot ne doit jamais être déduite de sa
valeur.

### B2 — Le profileur est câblé en production et sa trace n'est jamais réinitialisée en worker
`src/DurableBundle/DependencyInjection/DurableExtension.php:96`, `:744-748` ·
`src/DurableBundle/EventListener/ResetDurableProfilerListener.php:24`
**Trois grilles indépendantes (DX, profiler, HTTP).**

`registerProfiler()` est appelé sans condition, en premier dans `load()`, et pose le tag
`data_collector` quels que soient `kernel.debug` et la présence du WebProfilerBundle. Le même
service `durable.execution_trace` est aliasé sur `WorkflowExecutionObserverInterface` et injecté
dans `ExecutionRuntime` (`:479`), `ExecutionEngine` (`:550`) et `ActivityMessageProcessor` (`:716`) :
il instrumente l'exécution en production. Il n'a **pas** de tag `kernel.reset` et n'est vidé que par
un listener `kernel.request` — dans `messenger:consume`, où il n'y a pas de requête, la trace grossit
sans borne. FrameworkBundle charge ses collecteurs depuis des fichiers séparés
(`cache_debug.php`, `debug_prod.php`) sous condition.

*Correctif* : isoler la plomberie de profil dans un `config/debug.php` chargé si `%kernel.debug%`,
poser `kernel.reset`, et servir un observateur nul hors debug.

### B3 — Le DataCollector ne `cloneVar` jamais : un payload non sérialisable casse tout le profil
`src/DurableBundle/DataCollector/DurableDataCollector.php:643`, `:95`, `:842`

`'payload' => $event->payload()` entre brut dans `$this->data`, et `__serialize()` renvoie le tableau
tel quel. Un objet non sérialisable dans une charge utile de workflow — une `Closure`, une
connexion, un `PDO` — casse la sérialisation du profil **entier**, pas seulement du panneau Durable.
`cloneVar()` existe précisément pour ça.

### B4 — `doctrine:migrations:diff` génère des `DROP TABLE` sur le journal
`src/Bridge/Dbal/Schema/DurableSchema.php:56`, `:15` ·
`src/DurableBundle/DependencyInjection/DurableExtension.php:143`

Les quatre tables du pont ne sont déclarées à Doctrine par aucun `configureSchema` ni écouteur
`postGenerateSchema` — **alors qu'un docblock du fichier l'affirme**. Elles sont donc inconnues de
`doctrine:schema:update` comme des migrations, qui les voient comme des tables orphelines. Le
`DoctrineTransport` de Messenger, cité en modèle par le code lui-même, résout exactement ce problème.

### B5 — Le premier workflow du guide ne s'enregistre pas
`documentation/user/getting-started/_index.md:183` · `documentation/user/activities/_index.md:33`

Le tutoriel pose `#[AsActivity]` sur l'implémentation, alors que `DurableBundle::build()`
n'autoconfigure que `#[AsActivityHandler]`. Le lecteur suit le guide et rien ne se branche.

### B6 — Le parcours d'arrivée ne mène jamais à un résultat visible
`documentation/user/getting-started/_index.md:243`

Le guide s'arrête sur un `dispatchNewWorkflowRun()` qui rend `void`, sans jamais indiquer de
consommateur pour le profil in-memory qu'il prescrit. `durable:sample`, qui draine tout seul, n'est
cité nulle part.

---

## Exposer l'état en API (chantier `durable-apiplatform`)

La question posée était : un `ProviderInterface` / `ProcessorInterface` API Platform peut-il se poser
sur les contrats actuels ? **Non pour les opérations d'item, oui pour les collections.**

- **Bloquant** — `WorkflowRunCatalogInterface` n'a aucune lecture d'un run isolé : `readHistory()`
  prend une `WorkflowRunDescription`, pas un identifiant. `GET /workflow_runs/{id}` est
  inimplémentable, et les `@id` de collection pointeraient dans le vide.
  (`src/Durable/Port/WorkflowRunCatalogInterface.php:34-57`)
- **Bloquant** — côté écriture, `dispatchNewWorkflowRun()` rend `void` ; l'identifiant est inventé
  par l'appelant et devient le **workflowId** sur Temporal (donc un `groupId`) alors qu'il *est* le
  `runId` sur DBAL. Une IRI `Location:` résoudrait sur un backend et pas sur l'autre.
  (`src/Durable/Port/WorkflowResumeDispatcher.php:25`, `src/Bridge/Temporal/WorkflowClient.php:53-63`)
- **Majeur** — aucun backend ne donne de total : plafond à `PartialPaginatorInterface`, et le curseur
  opaque est incompatible avec les liens `page` d'Hydra.
- **Sain, contre l'hypothèse de départ** — la pagination par clé existe et est bien faite
  (`LIMIT n+1`), et il n'y a **pas** de couplage au backend : DTO `readonly` et suite de conformité.
  Les tableaux bruts ne sont que dans `RunDashboard`, modèle taillé pour Twig.

---

## Majeurs — cœur

| # | Constat | Ancre | Grilles |
|---|---------|-------|---------|
| M1 | **Le rejeu est quadratique.** `EventStoreHistorySource` ouvre son propre `readStream()` complet dans chacune de ses 15 méthodes, sans mémoïsation ; `activity()` en déclenche trois par activité. Sous DBAL : une requête SQL **et** un mapping par ligne à chaque balayage — des centaines d'allers-retours base par tâche. Les SDK Temporal construisent l'état en une seule passe ordonnée. | `Store/EventStoreHistorySource.php:44` | 2 |
| M2 | **`version()` ignore `$minSupported`.** Retirer une vieille branche fait basculer silencieusement un run en vol sur la neuve. | `ExecutionContext.php:211-217` | 1 |
| M3 | **Un continue-as-new coupe la chaîne** : métadonnées supprimées, identifiant neuf, aucun lien — l'exécution est irrécupérable. | `Handler/ResumeWorkflowHandler.php:89-93` | 1 |
| M4 | **Les `finally` du workflow s'exécutent à chaque passe.** Le fiber abandonné à chaque suspension est détruit, donc PHP déroule sa pile (vérifié sur 8.2.33 contre la RFC Fibers). Un `finally` qui attend prend `FiberError`, levé hors de tout `WorkflowLifecycleInterface`. | `Worker/WorkflowFiberDriver.php:89-91` | 1 |
| M5 | **Une valeur de suspension non reconnue abandonne le run en silence** : `break`, aucun rappel de cycle de vie, exécution « non complétée » que rien ne reprogramme. Le mode de panne le plus coûteux à diagnostiquer du moteur, atteint par un `break`. | `Worker/WorkflowFiberDriver.php:63-65` | 1 |
| M6 | **La raison de l'attente est calculée à chaque suspension puis jetée** dans le chemin Messenger de production ; seul `InMemoryWorkflowRunner` la lit. | `Handler/ResumeWorkflowHandler.php:70-72` | 1 |
| M7 | **Les objets valeur ne franchissent pas les frontières.** `ExecutionId` : 0 type-hint contre 152 `string $executionId`. `WorkflowHistorySourceInterface` rend cinq `array{…}` de forme alors que sa propre docstring et une tâche cochée du change `value-objects-through-ports` annoncent des `Duration`. | `Port/WorkflowHistorySourceInterface.php:10,23,58` | 3 |
| M8 | **Les invariants ne sont pas validés** : un seul `throw` de validation sur ~60 types constructibles. Les objets sont gelés sans jamais avoir été vérifiés. | `Awaitable/QuorumAwaitable.php:37` | 1 |
| M9 | **Aucune interface marqueur d'exception** : impossible d'attraper « une erreur Durable » — 16 classes, hiérarchie plate, contre la convention Symfony. | `Exception/` (16 classes) | 1 |
| M10 | **`WorkflowCommandBufferInterface` : 5 méthodes sur 14** ne sont honorables que par un backend (3 corps vides côté Temporal, 2 qui lèvent côté journal) — et l'interface le documente comme intentionnel. | `Port/WorkflowCommandBufferInterface.php:35` | 1 |
| M11 | **`NullEventStore` n'est pas un objet nul** mais un bouche-trou de signature : `readStream()` rendant `[]` est indistinguable d'une exécution neuve, donc le workflow rejouerait ses activités au lieu d'échouer. | `Store/NullEventStore.php:44` | 1 |

## Majeurs — bundle Symfony

| # | Constat | Ancre | Grilles |
|---|---------|-------|---------|
| M12 | **48 `setPublic(true)` sur 60 services** (contre 13 privés), alias de décorateurs internes compris. Chaque service public échappe à l'inlining et à `RemoveUnusedDefinitionsPass`, et devient une promesse de compatibilité implicite. | `DependencyInjection/DurableExtension.php:157` (+47) | 2 |
| M13 | **Les middlewares s'insèrent en tête de _tous_ les bus** de l'application, sans opt-out : le verrou DBAL et le middleware de profil s'appliquent au bus de commandes métier d'un utilisateur qui n'a rien demandé. DoctrineBundle définit son middleware et laisse l'application l'ajouter. | `Compiler/RegisterDurableMiddlewarePass.php:38-57` | 2 |
| M14 | **Deux ponts référencés en dur, déclarés nulle part.** L'extension importe 16 classes du pont Temporal et 7 du pont DBAL ; le `composer.json` du bundle ne les mentionne pas, pas même en `suggest`. Le conteneur compile, puis fatal « class not found » au premier appel. Le paquet cœur, lui, le fait correctement. | `DurableBundle/composer.json:16-29` vs `DurableExtension.php:7-29` | 4 |
| M15 | **Le cache warmer est un no-op silencieux** : il écrit dans un pool d'exécution avec un TTL d'une heure au lieu du répertoire de build, et le pool par défaut est `null`. Aggravé par `hasDefinition()` qui rend `false` sur un **alias** — `Psr\Cache\CacheItemPoolInterface` en est un : l'utilisateur configure un pool, rien ne tourne, rien ne le signale. | `CacheWarmer/ActivityContractCacheWarmer.php:23-29` · `DurableExtension.php:503` | 4 |
| M16 | **Le conteneur instancie tous les gestionnaires d'activité pour en exécuter un seul** (`addMethodCall` + `Reference` au lieu d'un `ServiceLocator`) — vérifié dans le conteneur compilé du banc. | `Compiler/ActivityHandlerPass.php:75` | 1 |
| M17 | **`#[AsWorkflow]` existe mais n'est pas autoconfiguré.** Trois attributs sur quatre le sont ; les workflows se taguent à la main en YAML, par répertoire. L'incohérence est dans le bundle, pas dans l'application. | `DurableBundle.php:24-49` | 1 |
| M18 | **Le choix du backend n'existe pas comme nœud de config** : il est réparti sur `event_store.type`, `temporal.dsn` et `temporal.journal`, et la seule combinaison interdite est gardée par une `\LogicException` nue — donc sans chemin de configuration. Le garde est de plus **asymétrique** : `dbal` + DSN refuse dur, `in_memory` + DSN passe en silence. `mailer` fait la même exclusion en `->validate()->thenInvalid()`. | `DurableExtension.php:137-139`, `:424` | 1 |
| M19 | **`lock.factory` référencé sans vérifier que `framework.lock` est configuré**, et liste de backends fermée sans point d'extension. | `DurableExtension.php:162-166` | 1 |
| M20 | **Extension procédurale de 819 lignes**, sans `Resources/config`, identifiants en FQCN au lieu de `durable.*` — l'ordre d'appel des 18 méthodes de `load()` est devenu la structure du conteneur. | `DurableExtension.php:86-115` | 1 |

## Majeurs — Messenger et transports

| # | Constat | Ancre |
|---|---------|-------|
| M21 | **`retry_strategy`, `failure_transport` et `--limit` sont de la configuration morte.** Les trois transports Temporal receive-only exécutent le travail dans `get()` et retournent `[]` : aucune enveloppe n'atteint jamais le `Worker`. | `Bridge/Temporal/Messenger/TemporalJournalTransport.php:29-34` (+2) |
| M22 | **Les erreurs gRPC sortent brutes** de la boucle du `Worker`, hors du contrat `TransportException`. | `TemporalJournalTransport.php:31` |
| M23 | **Le verrou de reprise est pris aussi au dispatch**, faute de garde `ReceivedStamp` : une requête HTTP peut bloquer jusqu'à 300 s. | `Bridge/Dbal/Messenger/SingleResumeLockMiddleware.php:41-42` |
| M24 | **`MessengerActivityTransport` acquitte avant traitement** et retient une enveloppe sans jamais la rendre. | `DurableBundle/Transport/MessengerActivityTransport.php:59-73` |
| M25 | **Le retry des activités est réimplémenté à côté de Messenger**, sans jamais utiliser `UnrecoverableMessageHandlingException`. | `Durable/Worker/ActivityMessageProcessor.php:138-195` |

## Majeurs — plugin Sylius

| # | Constat | Ancre | Grilles |
|---|---------|-------|---------|
| M26 | **La route fige `/admin/` au lieu de `%sylius_admin.path_name%`.** Changer `SYLIUS_ADMIN_ROUTING_PATH_NAME` fait basculer la page sous le pare-feu boutique. Ce n'est **pas** un trou d'authentification — `IsGranted` refuse toujours — c'est un défaut de portabilité. Tous les plugins amont (Refund, Adyen) importent leurs routes admin avec ce préfixe. | `DurablePlugin/Resources/config/routes.yaml:2` | **4** |
| M27 | **C'est un bundle Symfony qui porte le nom « plugin »** : `type: symfony-bundle`, ni `SyliusPluginTrait`, ni `getPath()`, arborescence `Resources/` de Sylius 1 là où le squelette 2.0 pose `config/`, `templates/`, `translations/` à la racine. Et le `composer.json` **ne déclare aucune dépendance Sylius**, alors que son unique gabarit étend `@SyliusAdmin/shared/layout/base.html.twig`. | `DurablePlugin/composer.json:15`, `:33-35` | 2 |
| M28 | **Rien n'est traduisible** : zéro `|trans` dans tout le paquet, libellé de menu et 270 lignes de gabarit en anglais littéral, aucun `translations/`. | `EventListener/AdminMenuListener.php:37` | 2 |
| M29 | **Le châssis d'admin est reconstruit à la main** au lieu d'être composé par le hook `sylius_admin.common.index`, et **la liste est rendue à la main** sans lien « page précédente » alors que `sylius/grid-bundle` documente un `DataProviderInterface` non-Doctrine. La friction curseur/`Pagerfanta` est réelle mais n'est argumentée ni dans DUR049 ni dans la proposition. | `Resources/views/admin/dashboard/index.html.twig:61-66`, `:136-168` | 1 |
| M30 | **L'entrée de menu contourne le contrat Sylius** par `object` + `method_exists`, et est déclarée par `uri` — elle n'est donc jamais marquée active. | `EventListener/AdminMenuListener.php:15-41` | 2 |

## Majeurs — outillage, paquets, tests, documentation

| # | Constat | Ancre |
|---|---------|-------|
| M31 | **Le pont Temporal revendique le PSR-4 `Temporal\Api\`** et écrase silencieusement celui que `temporal/sdk` tire via `roadrunner-php/roadrunner-api-dto`, sans `conflict` pour l'interdire. | `Bridge/Temporal/composer.json:31` |
| M32 | **`self.version` sur les onze liens inter-paquets rend la ligne alpha publiée non installable** (`minimum-stability` est root-only). Recoupe ce qui avait déjà été constaté à l'installation. | `DurableBundle/composer.json:18` (+10) |
| M33 | **`gplanchat/durable-magento` est publié par splitsh mais absent du graphe racine**, et aucun `composer validate` en CI : les manifestes des paquets publiés sont structurellement invérifiables — trois sont effectivement faux. | `bin/splitsh-publish.sh:39` vs `composer.json:11-48` |
| M34 | **La baseline Psalm est morte à 87 %**, et `findUnusedBaselineEntry` est explicitement éteint. PHPStan est au niveau 5 sur 10 : les quinze formes de tableaux déclarées dans les ports ne sont **pas** vérifiées (niveau 6 minimum), et les casts `(string) $payload[…]` sur du `mixed` à la frontière de désérialisation passent sans signalement. Nuance mesurée : la baseline **ne masque aucun vrai bug** (25 entrées réelles sur 196, toutes des invariants), et l'écart niveau 5 → 8 vaut 67 diagnostics, dont 13 au seul niveau 6. | `psalm.xml:8`, `:42` · `phpstan.neon:5` |
| M35 | **Aucun adaptateur Temporal ne joue de suite de conformité DUR041.** Trois sont concernés — les deux stores d'événements et le catalogue de runs —, pour deux ports ; les trois autres backends jouent les quatre suites. L'ADR l'annonçait pourtant au présent (« they run this tier there », « Temporal's read-through store gets checked for the first time »), et le docbloc du cœur reprenait le même énoncé. *Repris depuis : DUR041 et le docbloc portent l'état réel.* | `Testing/EventStoreReplayConformanceTestCase.php:24` vs `adr/DUR041…md:5`, `:115` |
| M36 | **La conformité SQL n'est prouvée que contre SQLite en mémoire**, un moteur dont le dépôt a déjà écrit qu'il avalait deux fautes DBAL. | `tests/unit/Bridge/Dbal/DbalEventStoreConformanceTest.php:21` |
| M37 | **Le milieu de la pyramide est quasi vide** : 134 classes unitaires, 6 d'intégration sans infrastructure, 19 gated sur un serveur Temporal — l'étage déclaré par DUR010 manque. Behat : douze dépendances et 100 lignes de config pour **zéro scénario projet et zéro job CI**. | `phpunit.xml:35` · `sylius/features/` |
| M38 | **La référence de configuration se dit exhaustive et ignore le nœud `dbal` en entier** (quatre `table_name`, la valeur `dbal` de trois énumérations), et se trompe sur le défaut d'`activity_transport.type`. | `documentation/user/configuration/_index.md:8` |
| M39 | **Le README du pont Temporal documente 2 des 4 `purpose`** que la fabrique accepte — les deux manquants étant ceux que le guide utilisateur fait écrire. | `Bridge/Temporal/README.md:17` vs `TemporalTransportFactory.php:70,80,90` |
| M40 | **Navigation documentaire plate** : 17 sections sœurs, la référence avant le tutoriel (`packages` weight 5 vs `getting-started` 10), l'explication en huitième position, **aucun glossaire** — « curseur » est dans l'argumentaire du README et défini nulle part, « Nexus » apparaît une fois sans définition. | `documentation/user/packages/_index.md:3` |
| M41 | **Le banc Symfony : un GET démarre un workflow et l'exécute intégralement dans la requête**, avec un `catch \Throwable` qui court-circuite `kernel.exception`. | `symfony/src/Controller/SamplesWorkflowController.php:33`, `:49-72` |

---

## Ce qui est sain — et vérifié comme tel

- **Désérialisation du journal** : zéro `unserialize`, zéro `eval`. `EventDataMapper` est une liste
  blanche `match` fermée, `WorkflowRegistry` n'appelle qu'un type préenregistré. Un journal falsifié
  n'instancie rien d'arbitraire.
- **Immuabilité** : 69 `readonly class`, 222 propriétés `readonly`, 29/29 événements
  `final readonly`, zéro setter, zéro classe non-`final`.
- **PHP 8.2+** : plancher réellement tenu, aucune syntaxe 8.3/8.4 hors code généré. 10 enums,
  `match(true)`, types de propriété partout.
- **Matrice CI** : 6.4 LTS × 7.4 LTS × plancher et tête de la ligne 8, en `lowest` et `highest`,
  PHPStan à chaque entrée, chaque ligne portant la raison d'être du bord qu'elle mesure.
- **Migrations BC** : le jeu Rector `durable-upgrade` couvre les treize renommages d'alpha8 et
  `UPGRADE.md` documente la seule suppression que Rector ne peut pas exprimer.
- **Aucune collision avec `symfony/workflow`** : racine `durable` vs `framework.workflows`, tag
  `durable.workflow` vs `workflow`, zéro collision de nom court. La collision est purement lexicale.
- **Isolation du backend annoncée par DUR037** : elle tient — zéro mention de `Bridge\`, `Temporal`,
  `Dbal` ou `grpc` dans le plugin.
- **Documentation** : les 51 FQCN cités résolvent, aucun exemple PHP ne casse, DUR006/030/037/038/041
  existent et disent bien ce qu'on leur fait dire.
- **Sécurité du dashboard** : doublement protégé, `#[IsGranted]` **et** `access_control`.
- **Ordre des passes de compilation** : correct et vérifié contre `PassConfig`, avec justification
  écrite de chaque priorité. Position de la pile de middlewares correcte (au-dessus de
  `doctrine_transaction` et `handle_message`).
- **Twig du collector** : pas de `|raw`, `json_encode` ré-échappé par l'autoescape, états vides
  traités.
- **`Attribute/`, `Query/`, `EventDataMapper`** : corrects, cibles justes, `class-string` là où il
  faut, formes de retour exactes.
- **Métadonnées Composer** : `license`/`description`/`authors`/`keywords`/`type` partout, aucun
  `@dev` qui fuit hors racine, contraintes tierces en `^`, `branch-alias` cohérent avec les tags.
- **Modèle de suspension** : un seul `Fiber::suspend()` dans tout le cœur, un pilote unique, et la
  livraison d'annulation par `Fiber::throw()` au point de suspension est conforme à la RFC.
- **Conventions de test** : la règle « jamais de `run()` ni `fail()` comme méthode d'aide » est
  tenue ; l'ordre de tableau est traité explicitement.

---

## Les vingt axes

| Axe | Ce qu'il couvre | Constats |
|---|---|---|
| [Cohérence Symfony](01-coherence-symfony.md) | minimalisme de la surface publique, DX d'installation | 8 |
| [PHP moderne, Fibers](02-php-moderne-fibers.md) | rejeu et coût du chemin chaud | 8 |
| [Contrats et typage](03-contrats-typage.md) | `Port/`, `Store/`, `Query/`, `Mapping/`, `Attribute/` | 8 |
| [État d'API](04-etat-api.md) | pagination, modèle de lecture HTTP, exposer les runs en API | 8 |
| [Conteneur et performance](05-conteneur-performance.md) | paresse des services, coût au boot et au rejeu | 8 |
| [Configuration](06-configuration.md) | validation, messages d'erreur, normalisation | 8 |
| [Compatibilité, analyse statique](07-compatibilite-statique.md) | dépréciations, matrice CI, analyse statique | 6 |
| [Console et sécurité](08-console-securite.md) | signature, sortie, codes de retour | 8 |
| [Routing et HTTP](09-routing-http.md) | routes, listeners kernel, sémantique des réponses | 5 |
| [API publique](10-api-publique.md) | immutabilité, nommage, objets valeur, invariants | 8 |
| [Vocabulaire et exploitation](11-vocabulaire-exploitation.md) | cohabitation Symfony, versionnage, ergonomie d'exploitation | 6 |
| [Interop et Doctrine](12-interop-doctrine.md) | points d'extension, Doctrine, ordre de chargement | 8 |
| [Documentation](13-documentation.md) | conventions des composants récents, doc contre code | 8 |
| [Passes et profiler](14-passes-profiler.md) | collecte, sérialisation des données collectées | 8 |
| [Messenger](15-messenger.md) | transports, middleware, stamps, retry | 8 |
| [Prise en main](16-prise-en-main.md) | structure documentaire, chemin du premier succès | 8 |
| [Composer et publication](17-composer-publication.md) | métadonnées, contraintes, publication depuis un monorepo | 8 |
| [Plugin Sylius](18-plugin-sylius.md) | conformité au squelette officiel, compatibilité Sylius 2 | 8 |
| [Tests](19-tests.md) | Behat, pyramide, isolation et déterminisme | 6 |
| [Back-office Sylius](20-admin-sylius.md) | layout, grilles de ressources, menu, Twig, accessibilité | 8 |

Chaque rapport détaille ses constats avec, pour chacun, la référence amont qui fonde la règle et
le correctif proposé.
