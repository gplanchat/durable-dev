# Sémantique de l'arbre de configuration — validation, messages d'erreur, normalisation

## Synthèse

L'arbre `durable` est court, entièrement typé et couvre son périmètre : les trois nœuds `type`
(`event_store`, `workflow_metadata`, `child_workflow.parent_link_store`) et `activity_transport.type`
sont de vrais `enumNode()` à `values()` explicites, et chaque nœud a une valeur par défaut — la
question « des scalaires qui devraient être des enums » ne se pose donc que pour les identifiants de
service et pour `temporal.dsn`. Le défaut central est ailleurs : le choix du backend n'est pas porté
par un nœud mais réparti sur trois (`event_store.type`, `temporal.dsn`, `temporal.journal`), et la
seule combinaison interdite est gardée depuis l'extension par une `\LogicException` nue plutôt que
par `->validate()->thenInvalid()`, ce qui prive le message de son chemin de configuration. Les
identifiants de service qui arrivent de la config (pool PSR-6, transport Messenger, connexion DBAL,
`lock_factory`) ne sont ni validés ni rapportés par leur chemin : le pool est avalé en silence, les
autres échouent avec un message qui ne nomme jamais l'option fautive. Enfin `temporal.dsn` est un
scalaire libre dont la seule validation vit dans une fabrique appelée à l'exécution — le dépôt
documente lui-même le symptôme dans `sylius/config/packages/durable.yaml`.

## Constats

### C1 — La seule contrainte inter-nœuds est une `\LogicException`, pas un `thenInvalid()`

- **Fichier** : `src/DurableBundle/DependencyInjection/DurableExtension.php:137-139`
- **Gravité** : majeur
- **Constat** : le couple interdit `event_store.type: dbal` + Temporal natif est refusé depuis
  `registerDbalStores()` par un `throw new \LogicException(...)`. La contrainte est pourtant
  purement configurationnelle : elle se calcule sur `$config` seul, sans jamais consulter le
  conteneur. Le message obtenu n'est donc préfixé d'aucun chemin (`durable.event_store.type`), n'est
  pas une `InvalidConfigurationException`, et échappe à ce que `lint:container` sait rapporter.
- **Amont** : `vendor/symfony/framework-bundle/DependencyInjection/Configuration.php:2332-2336` —
  `mailer` exprime exactement ce genre d'exclusion mutuelle en `->validate()->ifTrue(...)
  ->thenInvalid('"dsn" and "transports" cannot be used together.')`. Le pendant en extension
  (`FrameworkExtension.php:2586-2587`, `failure_transport`) n'est employé que là où le contrôle a
  besoin de l'état du conteneur — ce qui n'est pas le cas ici.
- **Correctif** : déplacer le test dans `Configuration::getConfigTreeBuilder()`, en `->validate()`
  sur le nœud racine, avec `->thenInvalid()` : le texte actuel est bon, il lui manque seulement le
  chemin que `thenInvalid()` ajoute gratuitement.

### C2 — Le garde du choix de backend n'est posé que sur une branche sur deux

- **Fichier** : `src/DurableBundle/DependencyInjection/DurableExtension.php:424` (et `:432`)
- **Gravité** : majeur
- **Constat** : déclarer un journal que `temporal.dsn` vient ensuite recouvrir provoque un refus dur
  si ce journal est `dbal` (`:137`), et pas un mot s'il est `in_memory` : l'alias
  `EventStoreInterface` est réécrit vers `durable.event_store.temporal` sans que la valeur écrite
  dans `event_store.type` soit jamais confrontée à `temporal.dsn`. C'est la même classe d'erreur
  utilisateur, traitée deux fois différemment ; `symfony/config/packages/durable.yaml:27-30`
  illustre le cas — `type: in_memory` y coexiste avec un DSN sans que rien ne le signale.
- **Amont** : `vendor/symfony/framework-bundle/DependencyInjection/Configuration.php:2332-2336` —
  la règle amont est qu'une exclusion mutuelle se déclare une fois, sur le nœud parent, pour toutes
  ses combinaisons, et non branche par branche à l'endroit du câblage.
- **Correctif** : faire porter le choix par un seul nœud — un `enumNode('backend')` à trois valeurs
  (`in_memory`/`dbal`/`temporal`), ou à défaut un `->validate()` unique sur `durable` qui refuse
  tout `event_store.type` explicite en présence d'un `temporal.dsn` avec `journal: true`.

### C3 — `temporal.dsn` est un scalaire libre, validé seulement à l'instanciation du service

- **Fichier** : `src/DurableBundle/DependencyInjection/Configuration.php:36-39`
- **Gravité** : majeur
- **Constat** : le nœud n'a ni `cannotBeEmpty()`, ni contrainte de schéma. La seule vérification est
  `TemporalConnection::fromDsn()` (`src/Bridge/Temporal/TemporalConnection.php:96-98`), posée en
  `setFactory()` (`DurableExtension.php:339-341`) donc exécutée à l'instanciation. Une faute de
  frappe produit `InvalidArgumentException: Invalid temporal:// DSN`, sans chemin de configuration
  et à un moment arbitraire ; `sylius/config/packages/durable.yaml:17-21` décrit précisément ce
  symptôme observé sur `doctrine:schema:create`.
- **Amont** : `vendor/symfony/dependency-injection/Compiler/ValidateEnvPlaceholdersPass.php:29`
  (`TYPE_FIXTURES = ['array' => [], 'bool' => false, ..., 'string' => '']`) — les placeholders
  `%env()%` sont remplacés par une chaîne vide avant le passage dans l'arbre, donc une validation
  déclarative sur ce nœud ne casse pas les configurations pilotées par variable d'environnement.
- **Correctif** : ajouter sur le nœud un `->validate()->ifTrue(fn ($v) => null !== $v && '' !== $v
  && !preg_match('#^temporal(-journal|-application)?://#i', $v))->thenInvalid(...)` ; l'erreur
  devient `durable.temporal.dsn` à la compilation, et la fabrique reste le filet de sécurité.

### C4 — Les identifiants de service venus de la config ne sont ni validés ni rapportés par leur chemin

- **Fichier** : `src/DurableBundle/DependencyInjection/DurableExtension.php:503-505`
- **Gravité** : majeur
- **Constat** : `activity_contracts.cache` passe par `$container->hasDefinition($cacheId)` — un pool
  mal orthographié, ou simplement déclaré comme *alias*, retombe silencieusement sur `null` et le
  cache de contrats disparaît sans un mot. Le contrôle dépend en outre de l'ordre des bundles :
  `cache.app` n'existe que parce que `symfony/config/bundles.php:4` place FrameworkBundle avant
  `DurableBundle` (`:6`). Les trois autres identifiants — `messenger.transport.<transport_name>`
  (`:452-455`), `dbal.connection` (`:141`), `dbal.lock_factory` (`:163`) — partent en `Reference`
  brute : l'échec arrive bien à la compilation, mais le message parle d'un service introuvable et ne
  nomme jamais l'option Durable qui l'a produit.
- **Amont** : `vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php:2586-2587`
  — quand un identifiant vient de la configuration, FrameworkBundle lève en nommant le concept de
  configuration (« the failure transport "%s" is not a valid transport or service id »), il ne
  dégrade jamais en silence.
- **Correctif** : remplacer `hasDefinition()` par `has()` et lever au lieu d'ignorer ; pour les
  trois autres, encadrer la `Reference` d'un test `$container->has()` avec un message citant
  `durable.activity_transport.transport_name` / `durable.dbal.connection`. Ajouter `cannotBeEmpty()`
  sur ces scalaires (`Configuration.php:22,23,51`), une chaîne vide donnant aujourd'hui un
  `Reference('')`.

### C5 — `activity_transport.table_name` est un nœud fantôme

- **Fichier** : `src/DurableBundle/DependencyInjection/Configuration.php:50`
- **Gravité** : mineur
- **Constat** : le nœud est déclaré avec le défaut `durable_activity_outbox` et exposé jusque dans le
  fichier de référence du banc (`symfony/config/reference.php:708`), mais aucun code ne le lit :
  `registerActivityTransport()` (`DurableExtension.php:438-464`) n'utilise que `type` et
  `transport_name`, et `registerCommands()` (`:721-724`) que ces deux-là également. Il apparaît dans
  `config:dump-reference`, invite à une valeur, et la jette.
- **Amont** : non sourcé (règle générale : l'arbre de configuration est la surface publique du
  bundle, `config:dump-reference` en est la documentation).
- **Correctif** : supprimer le nœud — ou, si une table d'outbox est prévue, le brancher ; laisser un
  nœud documenté sans effet est le pire des deux.

### C6 — Les `->info()` sont sur les nœuds descriptifs, pas sur ceux qui gouvernent le câblage

- **Fichier** : `src/DurableBundle/DependencyInjection/Configuration.php:29`
- **Gravité** : mineur
- **Constat** : `dbal` (`:20`) et `temporal.journal` (`:42`) portent des `info()` de plusieurs
  phrases, tandis que les nœuds dont dépend réellement le montage n'en ont aucun :
  `event_store.type` (`:29`), `activity_transport.type` (`:49`), `activity_transport.transport_name`
  (`:51`), `max_activity_retries` (`:54`), `child_workflow.async_messenger` (`:69`),
  `workflow_metadata.type` (`:82`). `max_activity_retries` n'a par ailleurs pas de `->min(0)`.
- **Amont** : `vendor/symfony/framework-bundle/DependencyInjection/Configuration.php:1766` et
  `:1793-1797` — messenger documente chaque option scalaire et borne systématiquement ses entiers
  (`->integerNode('max_retries')->defaultValue(3)->min(0)`).
- **Correctif** : une ligne d'`info()` par nœud restant, et `->min(0)` sur `max_activity_retries`.

### C7 — Aucune normalisation de forme courte

- **Fichier** : `src/DurableBundle/DependencyInjection/Configuration.php:26-53`
- **Gravité** : mineur
- **Constat** : chaque section n'accepte que sa forme longue. `durable: { event_store: dbal }` ou
  `durable: { temporal: 'temporal://localhost:7233' }` sont rejetés, alors que ce sont les deux
  écritures qu'un utilisateur tente d'abord, l'un de ces nœuds n'ayant qu'une seule clé signifiante.
  Aucun `beforeNormalization()` ni `acceptAndWrap()` n'apparaît dans le fichier.
- **Amont** : `vendor/symfony/framework-bundle/DependencyInjection/Configuration.php:1759-1765` —
  le prototype de `messenger.transports` déclare `->acceptAndWrap(['string'], 'dsn')`, ce qui permet
  d'écrire un transport comme une simple chaîne de DSN (`Symfony\Component\Config\Definition\
  Builder\ArrayNodeDefinition::acceptAndWrap()`, `vendor/symfony/config/.../ArrayNodeDefinition.php:95`).
- **Correctif** : `->acceptAndWrap(['string'], 'dsn')` sur `temporal` et
  `->acceptAndWrap(['string'], 'type')` sur `event_store`, `workflow_metadata`,
  `activity_transport` et `parent_link_store`.

### C8 — Deux extensions sans `Configuration` : leur clé de config est acceptée puis ignorée

- **Fichier** : `src/Bridge/Temporal/DependencyInjection/TemporalBridgeExtension.php:14-18`
- **Gravité** : remarque
- **Constat** : `TemporalBridgeExtension::load()` et `DurablePluginExtension::load()`
  (`src/DurablePlugin/DependencyInjection/DurablePluginExtension.php:17-21`) reçoivent `$configs`
  et ne l'utilisent pas ; aucune classe `Configuration` n'accompagne ces extensions. Écrire
  `temporal_bridge: { foo: bar }` ou `durable_plugin: { ... }` dans un `config/packages/` ne
  produit donc ni erreur ni effet, et `config:dump-reference temporal_bridge` ne rend rien.
  `TemporalBridgeExtension::getAlias()` (`:20-23`) réexprime par ailleurs l'alias que la convention
  de nommage déduit déjà de la classe.
- **Amont** : `vendor/symfony/dependency-injection/Compiler/ValidateEnvPlaceholdersPass.php:59-66`
  — la passe ne valide une extension que si elle expose une `Configuration` ; sans elle, la section
  est hors de toute vérification.
- **Correctif** : si ces bundles n'ont réellement aucune option, c'est acceptable ; sinon, ajouter
  une `Configuration` minimale (ne serait-ce que vide) pour que toute clé inconnue soit rejetée.
