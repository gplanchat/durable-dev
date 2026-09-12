# Interopérabilité entre bundles — points d'extension, Doctrine, ordre de chargement

## Synthèse
Le pont DBAL prend bien une `Connection` **injectée** par identifiant de service configurable
(`durable.dbal.connection`, défaut `doctrine.dbal.default_connection`) : c'est la bonne moitié du
contrat, et elle place de fait le journal **dans** la transaction applicative ouverte — un fait
jamais énoncé ni dans DUR030 ni dans le README. L'autre moitié manque entièrement : les quatre
tables ne sont déclarées à Doctrine par **aucun** `configureSchema` ni écouteur `postGenerateSchema`,
alors que le docblock affirme le contraire, si bien que `doctrine:schema:update` et
`doctrine:migrations:diff` les voient comme des tables étrangères à supprimer. Côté conteneur,
l'ordre des passes est correct et vérifiable (les priorités 100/50/0 correspondent à
`PassConfig::$beforeOptimizationPasses`), mais le bundle référence en dur des classes de paquets
qu'il ne déclare ni en `require` ni en `suggest`, et un service `lock.factory` qui n'existe que si
`framework.lock` est configuré, sans garde ni message. Les points d'extension offerts à un tiers se
réduisent à l'écrasement d'alias : la liste des backends est un `enumNode` fermé.

## Constats

### C1 — Les tables du journal sont invisibles de l'outillage Doctrine, et le docblock prétend l'inverse
- **Fichier** : `src/Bridge/Dbal/Schema/DurableSchema.php:56` (et `:15`), `src/DurableBundle/DependencyInjection/DurableExtension.php:143`
- **Gravité** : bloquant
- **Constat** : le docblock de `addToSchema()` annonce « branché aussi sur `configureSchema` côté bundle » ; `grep -rn "configureSchema\|SchemaListener\|ToolEvents\|postGenerateSchema\|doctrine.event_listener" src/` ne renvoie que cette ligne de commentaire. Aucun service n'est tagué `doctrine.event_listener`, aucun `schema_filter` n'est prependé. Sur un banc comme `sylius/`, qui configure `doctrine_migrations` (`sylius/config/packages/doctrine_migrations.yaml:5`) et l'ORM (`sylius/config/packages/doctrine.yaml`), un `doctrine:migrations:diff` produira donc `DROP TABLE durable_events`, `durable_workflow_metadata`, `durable_workflow_runs`, `durable_child_workflow_parent_link` — le journal d'exécution effacé par une migration générée.
- **Amont** : `symfony/vendor/symfony/doctrine-messenger/Transport/DoctrineTransport.php:89` (`configureSchema(Schema, DbalConnection, \Closure $isSameDatabase)`) et `symfony/vendor/symfony/doctrine-messenger/Transport/Connection.php:357` ; le câblage est dans `symfony/vendor/doctrine/doctrine-bundle/config/messenger.php:56` — `doctrine.orm.messenger.doctrine_schema_listener` tagué `doctrine.event_listener` sur `postGenerateSchema` et `onSchemaCreateTable`. Même schéma pour `symfony/lock` : `symfony/vendor/doctrine/doctrine-bundle/config/orm.php:224` (`LockStoreSchemaListener`) et pour le cache PDO et le token provider remember-me.
- **Correctif** : enregistrer, sous garde `class_exists(Doctrine\ORM\Tools\ToolEvents::class)`, un listener tagué `doctrine.event_listener` sur `postGenerateSchema` qui appelle `DurableSchema::addToSchema()` en filtrant sur la connexion visée (motif `AbstractSchemaListener::getIsSameDatabaseChecker()`), et corriger le docblock `:56` tant que ce n'est pas fait.

### C2 — Le DDL s'exécute paresseusement sur la connexion applicative, sans garde de transaction ni interrupteur
- **Fichier** : `src/Bridge/Dbal/Schema/DurableSchema.php:34-53`
- **Gravité** : majeur
- **Constat** : `ensure()` est appelé en tête de **chaque** méthode des stores (`DbalEventStore.php:37,60,81`, `DbalWorkflowRunProjection.php:38,75`, `DbalWorkflowRunCatalog.php:44`…) et émet `CREATE TABLE` sur la connexion injectée, quel que soit l'état transactionnel. Comme cette connexion est celle de l'application (`DurableExtension.php:141`), un premier `append()` déclenché à l'intérieur d'une transaction métier — cas normal sous `messenger.middleware.doctrine_transaction` ou dans une requête Sylius — provoque sur MySQL un commit implicite de la transaction en cours. Il n'existe par ailleurs aucun équivalent de `auto_setup: false` pour désactiver la création paresseuse en production.
- **Amont** : `symfony/vendor/symfony/doctrine-messenger/Transport/Connection.php:426` et `:441` — le transport que DUR030 (`documentation/adr/DUR030-…​.md:81-82`) et `DurableSchema.php:15` disent suivre refuse explicitement l'auto-setup dans ce cas : `if (!$this->autoSetup || $this->driverConnection->isTransactionActive()) { throw $e; }`. Le motif amont est de plus **réactif** (rattraper `TableNotFoundException`), pas proactif à chaque appel.
- **Correctif** : ajouter une option `auto_setup` (défaut `true`) et court-circuiter `ensure()` si `$connection->isTransactionActive()`, ou basculer sur le motif amont — laisser passer la requête et ne créer qu'après `TableNotFoundException`.

### C3 — Le bundle référence des classes de paquets qu'il ne déclare pas
- **Fichier** : `src/DurableBundle/DependencyInjection/DurableExtension.php:7-13`, `src/DurableBundle/composer.json`
- **Gravité** : majeur
- **Constat** : l'extension importe et enregistre `Gplanchat\Bridge\Dbal\{Schema\DurableSchema, Store\*, Messenger\SingleResumeLockMiddleware}` (lignes 7-13, enregistrés en 143-182), mais `src/DurableBundle/composer.json` ne mentionne ni `gplanchat/durable-bridge-dbal`, ni `doctrine/dbal`, ni `symfony/lock` — pas même en `suggest`, alors que trois autres entrées `suggest` existent. Aucune garde `class_exists()` n'accompagne `registerDbalStores()`. Poser `event_store.type: dbal` sans le paquet donne une erreur de réflexion sur une classe absente, loin de la ligne de configuration fautive. Le même reproche vaut pour les imports `Gplanchat\Bridge\Temporal\*` (lignes 14-29), hors périmètre ici.
- **Amont** : convention Symfony des bundles-ponts, appliquée par DoctrineBundle lui-même : `symfony/vendor/doctrine/doctrine-bundle/src/DoctrineBundle.php:80-85` teste `class_exists(RegisterDatePointTypePass::class)` avant d'enregistrer la passe qui en dépend, et `:60` teste `$container->hasExtension('security')` avant de toucher à l'extension voisine.
- **Correctif** : ajouter `gplanchat/durable-bridge-dbal` en `suggest` du bundle et faire échouer `registerDbalStores()` sur `class_exists(DurableSchema::class)` avec le message « installez `gplanchat/durable-bridge-dbal` pour `event_store.type: dbal` ».

### C4 — `lock.factory` est référencé sans vérifier que `framework.lock` est configuré
- **Fichier** : `src/DurableBundle/DependencyInjection/DurableExtension.php:162-166`, `src/DurableBundle/DependencyInjection/Configuration.php:23`
- **Gravité** : majeur
- **Constat** : `SingleResumeLockMiddleware` reçoit `new Reference($config['dbal']['lock_factory'])`, défaut `lock.factory`. Ce service n'existe que si l'application a activé `framework.lock` — le banc `symfony/` n'a pas de `config/packages/lock.yaml`, seul `sylius/config/packages/lock.yaml` en pose un. Sans lui, `event_store.type: dbal` fait échouer la compilation sur un « service inexistant », alors que le verrou est décrit par DUR030 (`:65-76`) et le README comme la seule garde contre le rejeu concurrent. Aucun message n'oriente vers `framework.lock`.
- **Amont** : `symfony/vendor/doctrine/doctrine-bundle/src/DoctrineBundle.php:47-57` — DoctrineBundle supprime `doctrine.orm.listeners.pdo_session_handler_schema_listener` quand `session.handler` est absent plutôt que de laisser une référence pendante ; c'est la convention pour une dépendance optionnelle sur un service du FrameworkBundle.
- **Correctif** : garder l'enregistrement derrière `$container->has($config['dbal']['lock_factory'])` et lever une `InvalidConfigurationException` explicite (« `durable.event_store.type: dbal` requiert `framework.lock` — configurez un store partagé, pas un store par processus »).

### C5 — Les middlewares Durable sont insérés en tête de *tous* les bus de l'application
- **Fichier** : `src/DurableBundle/DependencyInjection/Compiler/RegisterDurableMiddlewarePass.php:38-57`
- **Gravité** : mineur
- **Constat** : la passe itère `findTaggedServiceIds('messenger.bus')` et insère ses middlewares dans chaque `%busId%.middleware`, sans configuration pour restreindre la liste. Dans une application Sylius, cela injecte le verrou de reprise dans les bus de commande et d'événement de Sylius et de tout autre bundle. Le middleware s'esquive sur les messages non-Durable (`SingleResumeLockMiddleware.php:36-39`), donc l'effet est aujourd'hui bénin, mais un bundle tiers modifie ici la pile d'un bus qui ne lui appartient pas, sans possibilité pour l'intégrateur de s'y opposer.
- **Amont** : la configuration amont des piles est **par bus** — `framework.messenger.buses.<bus>.middleware` (`symfony/vendor/symfony/framework-bundle/DependencyInjection/Configuration.php`, nœud `buses`) ; le commentaire de la passe (`:13-20`) constate à juste titre qu'aucune balise `messenger.middleware` n'existe en amont, mais en déduit un élargissement à tous les bus.
- **Correctif** : ajouter un nœud `durable.messenger.buses` (défaut : le ou les bus effectivement utilisés par Durable) et n'insérer que dans ceux-là.

### C6 — `ensure()` marque le schéma « vérifié » avant d'exécuter le DDL
- **Fichier** : `src/Bridge/Dbal/Schema/DurableSchema.php:36-52`
- **Gravité** : mineur
- **Constat** : `$this->ensured = true;` est posé en ligne 39, avant la boucle `executeStatement()` des lignes 50-52. Si un `CREATE TABLE` échoue — droits insuffisants, course entre deux workers qui démarrent ensemble —, tous les appels suivants du processus considèrent le schéma prêt et échouent ensuite en `TableNotFoundException`, sans jamais retenter. Le cas de course est réel ici puisque la création est déclenchée par la première écriture de n'importe quel worker.
- **Amont** : `symfony/vendor/symfony/doctrine-messenger/Transport/Connection.php:426-448` — la garde amont est portée par le rattrapage de `TableNotFoundException` autour de chaque requête, ce qui rend l'échec de setup naturellement retentable.
- **Correctif** : déplacer l'affectation après la boucle, ou rattraper l'exception de création en revérifiant l'existence de la table (une course perdue n'est pas une erreur).

### C7 — Un transport `temporal://` mal configuré casse `doctrine:schema:create`, et le contournement est dans la configuration du banc
- **Fichier** : `sylius/config/packages/durable.yaml:17-21`, `sylius/config/packages/messenger.yaml:25`, `src/Bridge/Temporal/Messenger/TemporalTransportFactory.php:42-44`
- **Gravité** : mineur
- **Constat** : `createTransport()` construit `TemporalConnection::fromDsn($dsn)` immédiatement et lève `InvalidArgumentException('Invalid temporal:// DSN…')` (`src/Bridge/Temporal/TemporalConnection.php:97`). Or l'écouteur de schéma de Doctrine reçoit un `tagged_iterator('messenger.receiver')` et doit **instancier** chaque transport pour tester son type : un transport `temporal://` dont l'env var est vide fait donc échouer `doctrine:schema:create`, avec un message qui ne parle ni de Doctrine ni de Messenger. La configuration du banc documente le contournement (ne déclarer le transport que dans les profils `demo*`) au lieu que le pont soit tolérant.
- **Amont** : `symfony/vendor/doctrine/doctrine-bundle/config/messenger.php:55-60` (`MessengerTransportDoctrineSchemaListener` construit sur `tagged_iterator('messenger.receiver')`) et `symfony/vendor/symfony/doctrine-bridge/SchemaListener/MessengerTransportDoctrineSchemaListener.php:34-47` (`foreach ($this->transports as $transport)`, puis `instanceof DoctrineTransport`).
- **Correctif** : différer la résolution du DSN dans les transports Temporal (construire la connexion au premier `get()`/`send()` plutôt qu'en fabrique), pour qu'un transport non utilisé soit instanciable sans DSN valide.

### C8 — Points d'extension : liste de backends fermée et un nom de table non configurable
- **Fichier** : `src/DurableBundle/DependencyInjection/Configuration.php:29`, `src/DurableBundle/DependencyInjection/DurableExtension.php:143-149`
- **Gravité** : mineur
- **Constat** : `enumNode('type')->values(['in_memory', 'dbal'])` ferme l'ensemble des backends : un bundle tiers qui voudrait fournir un `EventStoreInterface` (MongoDB, Elasticsearch…) ne peut pas s'annoncer par la configuration, il doit écraser l'alias `EventStoreInterface::class` dans une passe. Les services sont bien publics et aliasés sur l'interface, donc décorables, mais il n'existe **aucune** balise offerte à un tiers hormis `durable.activity_handler`, `durable.nexus_handler` et `durable.messenger.middleware` — rien côté stockage ni catalogue. Accessoirement, `durable.dbal.schema` est enregistré avec quatre arguments alors que `DurableSchema::__construct()` en accepte cinq : `durable_workflow_runs` est le seul nom de table qui ne peut pas être changé, ce que le README ne signale pas.
- **Amont** : non sourcé pour la fermeture de l'enum (choix de conception, pas de règle amont) ; pour la table non configurable, `symfony/vendor/symfony/doctrine-messenger/Transport/Connection.php:57` expose `table_name` comme toute autre option.
- **Correctif** : accepter en plus du couple `in_memory`/`dbal` un `service:` pointant un identifiant fourni par l'application, et passer le nom de la table `runs` comme les trois autres.

---

**Sains sur leur axe** : l'ordre des passes de compilation est correct et documenté — `ActivityHandlerPass` et `NexusHandlerPass` à 50 passent bien après `AttributeAutoconfigurationPass` (priorité 100 dans `symfony/vendor/symfony/dependency-injection/Compiler/PassConfig.php:43-49`) et avant les passes à 0 ; `RegisterDurableMiddlewarePass` à 10 passe avant `MessengerPass` (priorité 0) qui lit `%busId%.middleware` ; `DurableTemporalTransportFactoryPass` en `TYPE_BEFORE_REMOVING` passe après l'autowiring. L'exclusion mutuelle `event_store.type: dbal` / `temporal.dsn` (`DurableExtension.php:137-139`) est vérifiée à la compilation avec un message actionnable — c'est le bon endroit.
