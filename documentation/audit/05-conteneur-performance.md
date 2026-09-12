# Compilation du conteneur — paresse des services, coût au boot et au rejeu

## Synthèse
Le paquet cœur est propre sur l'axe des dépendances cachées : `grep` ne trouve aucun `use` hors `Gplanchat\` en dehors de `Psr\Cache` et de PHPUnit (cantonné à `Testing/`), donc `php >=8.2` + `psr/cache` décrivent bien ce que le cœur charge. Le reste du périmètre porte deux coûts structurels : le conteneur construit **tous** les gestionnaires d'activité pour en exécuter un seul, et il expose 48 services publics là où la convention Symfony depuis 4.0 est le privé par défaut. Sur le chemin de rejeu, il n'y a pas d'`array_merge` en boucle — l'axe est sain — mais `EventStoreHistorySource` relit le journal entier à chaque consultation de slot, ce qui donne un rejeu en O(commandes × longueur du journal) et, sur le backend DBAL, un `SELECT` complet par consultation. Le cache warmer, enfin, écrit dans un pool d'exécution avec un TTL d'une heure au lieu du répertoire de build : ce qu'il produit n'est ni invalidable par `cache:clear` ni préchargeable, et il est un no-op silencieux dans la configuration par défaut.

## Constats

### C1 — Le conteneur instancie tous les gestionnaires d'activité pour en exécuter un seul
- **Fichier** : `src/DurableBundle/DependencyInjection/Compiler/ActivityHandlerPass.php:75` (et `src/Durable/RegistryActivityExecutor.php:12`, `src/DurableBundle/DependencyInjection/DurableExtension.php:600`)
- **Gravité** : majeur
- **Constat** : la passe pose un `addMethodCall('register', [$activityName, [new Reference($invokerId), '__invoke']])` par méthode d'activité ; l'argument étant un `callable` réel, le conteneur doit construire chaque invocateur — donc chaque service gestionnaire et tout son graphe — au moment où `ActivityExecutor` naît. Le conteneur compilé du banc le montre : `symfony/var/cache/dev/ContainerFHx7IVm/getActivityExecutorService.php` construit onze `…ActivityHandler` en ligne avant de rendre l'exécuteur. `NexusHandlerPass.php:110` a exactement la même forme sur `NexusOperationRegistry`.
- **Amont** : `symfony/console/DependencyInjection/AddConsoleCommandPass.php:149-160` — `ServiceLocatorTagPass::register($container, $lazyCommandRefs)` + carte `nom => id`, précisément pour que seule la commande appelée soit instanciée ; `symfony/dependency-injection/Compiler/ServiceLocatorTagPass.php`.
- **Correctif** : passer à `RegistryActivityExecutor` un `ServiceLocator` (via `ServiceLocatorTagPass::register()`) plus la carte `nom d'activité => id de service`, et résoudre dans `execute()`. Même changement pour `NexusOperationRegistry`.

### C2 — Le rejeu relit le journal entier à chaque consultation de slot
- **Fichier** : `src/Durable/Store/EventStoreHistorySource.php:52` (et 104, 116, 158, 175, 211, 226, 241, 252, 279, 295, 329)
- **Gravité** : majeur
- **Constat** : les douze méthodes du port font chacune un `foreach ($this->eventStore->readStream(...))` complet, sans mémoïsation. `ExecutionContext::activity()` en déclenche trois à quatre par activité (`ExecutionContext.php:103`, `:111`, `:116`, `:274`), et `countRecordedMessages()` (`ExecutionContext.php:463`) boucle `messageAt($n)` — un balayage complet par message. Le rejeu est donc en O(commandes × longueur du journal), et sur DBAL chaque balayage est un `SELECT … WHERE execution_id = ? ORDER BY id` distinct (`src/Bridge/Dbal/Store/DbalEventStore.php:58-66`) : un workflow de 100 activités émet plusieurs centaines de lectures intégrales du même flux.
- **Amont** : le principe — mémoïser derrière un adaptateur mémoire ce qu'on relit sur un chemin chaud — est celui de `symfony/validator/Mapping/Factory/LazyLoadingMetadataFactory.php` (cache `ArrayAdapter` interposé). Pas de règle Symfony directement transposable ici : **constat non sourcé** sur la forme exacte du correctif.
- **Correctif** : matérialiser le flux une fois par instance d'`EventStoreHistorySource` (le rejeu porte sur un journal figé) et dériver les index par type de slot en une passe, plutôt qu'un balayage par question.

### C3 — Le cache warmer écrit dans un pool d'exécution, avec un TTL, et non dans le répertoire de build
- **Fichier** : `src/DurableBundle/CacheWarmer/ActivityContractCacheWarmer.php:23-29` (et `src/Durable/Activity/ActivityContractResolver.php:19`)
- **Gravité** : majeur
- **Constat** : `warmUp()` ignore `$buildDir`, pousse dans un pool PSR-6 quelconque et rend `[]` — donc aucune classe à précharger. Le résolveur pose un `expiresAfter(3600)` sur une clé qui ne contient que le nom de classe : le fruit du warmup expire une heure après le déploiement, et sur un pool partagé entre déploiements (Redis) une métadonnée de contrat modifiée reste servie jusqu'à une heure, car rien ne lie la clé à une ressource de code.
- **Amont** : `symfony/framework-bundle/CacheWarmer/AbstractPhpFileCacheWarmer.php:35-56` — `doWarmUp()` rend `false` sans `$buildDir`, le résultat va dans un `PhpArrayAdapter` du répertoire de build, sans TTL, et `warmUp()` rend la liste des classes à précharger.
- **Correctif** : calquer `AbstractPhpFileCacheWarmer` — écrire un `PhpArrayAdapter` sous `$buildDir` (donc effacé par `cache:clear`), supprimer le TTL, rendre la liste de préchargement.

### C4 — 48 `setPublic(true)` : ni inlining, ni suppression des définitions inutilisées
- **Fichier** : `src/DurableBundle/DependencyInjection/DurableExtension.php:157` (et 47 autres occurrences dans le même fichier)
- **Gravité** : majeur
- **Constat** : presque tout ce que l'extension enregistre est public, alias compris. Le conteneur compilé du banc garde 17 services `Gplanchat\…` publics et 3 alias publics. Un service public n'est ni inliné ni retiré, et son identifiant devient une API que l'on ne peut plus renommer sans BC.
- **Amont** : `symfony/dependency-injection/Compiler/InlineServiceDefinitionsPass.php:199` (`if ($definition->isPublic()` → pas d'inlining) et `Compiler/RemoveUnusedDefinitionsPass.php:41,47` (public → conservé) ; c'est la raison du passage au privé par défaut en Symfony 4.0.
- **Correctif** : privé par défaut ; ne laisser public que ce qu'un test d'intégration ou une commande récupère vraiment par `getContainer()->get()`, et faire passer le reste par l'autowiring ou un `ServiceLocator` de test.

### C5 — Le pool de cache des contrats est nul par défaut, et un pool déclaré en alias est silencieusement ignoré
- **Fichier** : `src/DurableBundle/DependencyInjection/DurableExtension.php:503` (et `Configuration.php:58`)
- **Gravité** : mineur
- **Constat** : `activity_contracts.cache` vaut `null` par défaut, donc `ActivityContractResolver` fonctionne sans cache et le warmer de C3, s'il est configuré, ne conserve rien — le warmup est un no-op complet. Et le garde est `hasDefinition($cacheId)` : un pool désigné par un alias (le cas d'un `cache.pool` remappé) donne `false` et le cache est abandonné sans un mot.
- **Amont** : `symfony/dependency-injection/ContainerBuilder::has()` couvre définitions **et** alias, là où `hasDefinition()` ne voit que les définitions ; le `framework.cache.pools` de FrameworkBundle produit des services adressés par identifiant de pool.
- **Correctif** : `$container->has($cacheId)` plutôt que `hasDefinition()`, et lever si `cache` est configuré mais introuvable au lieu de retomber sur `null` ; par défaut, pointer sur `cache.system`.

### C6 — Le journal DBAL exécute du DDL depuis le chemin de lecture, sans interrupteur
- **Fichier** : `src/Bridge/Dbal/Store/DbalEventStore.php:60` (et `src/Bridge/Dbal/Schema/DurableSchema.php:34-52`)
- **Gravité** : mineur
- **Constat** : `readStream()`, `readStreamWithRecordedAt()` et `countEventsInStream()` appellent `schema->ensure()`, qui au premier appel de chaque processus interroge le `SchemaManager` sur quatre tables puis émet les `CREATE TABLE` manquants. La configuration (`Configuration.php:18-25`) n'offre aucun moyen de le couper : chaque worker paie ce va-et-vient au boot, et la production mute son schéma depuis le chemin d'exécution.
- **Amont** : `symfony/messenger`, transport Doctrine — option `auto_setup` (défaut `true`, documentée comme à désactiver en production, la table étant alors créée par une migration).
- **Correctif** : ajouter un `dbal.auto_setup` (défaut `true`, `false` recommandé en production) qui court-circuite `ensure()`, le schéma étant déjà déclaré via `configureSchema`.

### C7 — Le drain synchrone tourne à vide en relisant tout le journal à chaque tour
- **Fichier** : `src/Durable/ExecutionRuntime.php:79-82` (et `:93`)
- **Gravité** : mineur
- **Constat** : `while (!$awaitable->isSettled()) { drainActivityQueueOnce(); checkTimers(); }` ne cède jamais la main et n'attend jamais ; `checkTimers()` relit le flux entier à chaque tour, et `drainActivityQueueOnce()` appelle `ActivityEventJournal::lastTerminalOutcome()` qui en relit un autre. Une attente sur minuteur non échu occupe donc un cœur à 100 % à rejouer le journal jusqu'à l'échéance. Le chemin ne concerne que `distributed=false` — le défaut du constructeur (`:51`), ce que le harness de test et `InMemoryWorkflowRunner` empruntent — l'extension posant `true` (`DurableExtension.php:478`).
- **Amont** : constat non sourcé (règle algorithmique, pas une convention Symfony).
- **Correctif** : calculer le prochain échéancier depuis `TimerWakeDelayCalculator` et dormir jusque-là quand la file d'activités est vide, plutôt que de boucler.

### C8 — Le bundle exige `symfony/uid`, qu'il n'utilise pas, et référence deux ponts qu'il ne déclare même pas en `suggest`
- **Fichier** : `src/DurableBundle/composer.json:28`
- **Gravité** : mineur
- **Constat** : aucun `Symfony\Component\Uid` n'apparaît dans `src/DurableBundle/` ni `src/Durable/` — le cœur génère ses UUID v7 en PHP pur (`src/Durable/Uuid/NativeUuidV7Generator.php`). À l'inverse, `DurableExtension.php:7-29` importe une vingtaine de classes de `Gplanchat\Bridge\Temporal\*`, `Gplanchat\Bridge\Dbal\*` et `Temporal\Api\Workflowservice\V1\WorkflowServiceClient`, absentes du `require` **et** du `suggest` du bundle (le cœur, lui, les suggère).
- **Amont** : convention Composer/Symfony — un paquet déclare ce qu'il charge ; `symfony/framework-bundle` liste en `suggest` chaque composant que son extension sait câbler.
- **Correctif** : retirer `symfony/uid` du `require` ; ajouter `gplanchat/durable-bridge-temporal` et `gplanchat/durable-bridge-dbal` en `suggest`, avec la mention que les blocs de configuration correspondants les exigent.
