# Cohérence Symfony — minimalisme de la surface publique, DX d'installation

## Synthèse

Le bundle boote sans configuration — chaque nœud de `Configuration.php` porte `addDefaultsIfNotSet()` ou une valeur par défaut — mais le guide de démarrage et l'app d'exemple font écrire **environ 33 lignes de YAML réparties sur trois fichiers** (`durable.yaml` 18 l., `messenger.yaml` 12 l., `services.yaml` 3 l.) pour un premier workflow, dont 10 lignes qui ne font que répéter les défauts du bundle. La surface publique est le point le plus éloigné des conventions : 48 des 60 services enregistrés par l'extension sont `setPublic(true)`, là où le Bundle Best Practices demande le privé par défaut avec alias depuis l'interface. La structure de l'extension s'écarte aussi de l'amont : 819 lignes de PHP procédural, aucun fichier `Resources/config/*.php`, des identifiants de service en FQCN au lieu du préfixe d'alias. Deux mécanismes redoublent ce que Symfony sait déjà faire : la liste `activity_contracts.contracts` réénumère à la main ce que les balises `durable.activity_handler` portent déjà, et le collecteur de profil est câblé inconditionnellement, son observateur passant sur le chemin chaud de production. Le point sain : l'autoconfiguration par attribut de `AsActivityHandler` / `AsNexusServiceHandler` est idiomatique.

## Constats

### C1 — Presque tout le conteneur du bundle est public
- **Fichier** : `src/DurableBundle/DependencyInjection/DurableExtension.php:157` (premier de 48 ; ex. aussi `:481`, `:553`, `:769`)
- **Gravité** : majeur
- **Constat** : `grep -c 'setPublic(true)'` sur l'extension donne 48, contre 13 `setPublic(false)`, pour 60 `->register(` — soit ~80 % de la plomberie interne exposée à `$container->get()`. Les alias eux-mêmes sont rendus publics (`:159`, `:212`, `:235`, `:748`), y compris pour des décorateurs internes (`durable.event_store.dbal.projecting`, `durable.workflow_metadata_store.in_memory.projecting`) que rien dans l'application n'a de raison de tirer du conteneur. Chaque service public est un point d'entrée que le compilateur ne peut plus retirer ni inliner, et une promesse de compatibilité implicite.
- **Amont** : https://symfony.com/doc/current/bundles/best_practices.html — « services not meant to be used by the application directly, should be defined as private. For public services, aliases should be created from the interface/class to the service id. »
- **Correctif** : passer tout en privé par défaut, ne garder public que les quelques services réellement tirés du conteneur (workers Messenger, catalogue lu par le tableau de bord) et exposer le reste par alias d'interface autowirable ; les services de debug se préfixent d'un point (`.durable.…`).

### C2 — Le profil est câblé en production, et son observateur est sur le chemin chaud
- **Fichier** : `src/DurableBundle/DependencyInjection/DurableExtension.php:96` (appel), `:763-774` (tag `data_collector`)
- **Gravité** : majeur
- **Constat** : `registerProfiler()` est appelé sans condition, en premier dans `load()`, et pose le tag `data_collector` quel que soit `kernel.debug` ou la présence du WebProfilerBundle. Le même service, `durable.execution_trace`, est aliasé sur `WorkflowExecutionObserverInterface` et injecté dans `ExecutionRuntime` (`:479`), `ExecutionEngine` (`:550`) et `ActivityMessageProcessor` (`:716`) — il instrumente donc l'exécution en prod, pas seulement en debug. Le profileur pèse par ailleurs ~1 350 des 3 643 lignes du bundle (`DataCollector/DurableDataCollector.php` 856 l. + `Profiler/` 499 l.), soit plus que l'extension elle-même. `ResetDurableProfilerListener` borne la croissance ; le reproche porte sur le câblage inconditionnel, pas sur une fuite.
- **Amont** : `symfony/vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php` charge `collectors.php` et `*_debug.php` sous condition (`Resources/config/cache_debug.php`, `debug_prod.php`, `http_client_debug.php`, `form_debug.php` sont des fichiers séparés du chemin nominal).
- **Correctif** : isoler la plomberie de profil dans un `Resources/config/debug.php` chargé seulement si `%kernel.debug%`, et faire de l'observateur un `NullWorkflowExecutionObserver` hors debug.

### C3 — `#[AsWorkflow]` existe mais n'est pas autoconfiguré : les workflows se déclarent en YAML
- **Fichier** : `src/DurableBundle/DurableBundle.php:24-49` (trois attributs autoconfigurés), `src/Durable/Attribute/AsWorkflow.php:8`
- **Gravité** : majeur
- **Constat** : `build()` enregistre `registerAttributeForAutoconfiguration()` pour `AsActivityHandler`, `AsNexusServiceHandler` et `FulfilsNexusOperation`, mais pas pour `AsWorkflow`, qui existe pourtant dans le cœur et que `WorkflowDefinitionLoader` lit déjà (`src/Durable/Workflow/WorkflowDefinitionLoader.php:94`). Conséquence : l'utilisateur doit poser la balise à la main par répertoire (`symfony/config/services.yaml:23-29`, deux blocs `resource:` + `tags: [durable.workflow]`), ce que le guide documente comme l'étape normale. Trois briques sur quatre se déclarent par attribut, la quatrième par convention de dossier — l'incohérence est dans le bundle, pas dans l'application.
- **Amont** : https://symfony.com/doc/current/bundles/best_practices.html (registre des services par balise) et le mécanisme `registerAttributeForAutoconfiguration` déjà employé aux lignes 24-49 du même fichier.
- **Correctif** : ajouter un quatrième `registerAttributeForAutoconfiguration(AsWorkflow::class, …)` posant `durable.workflow` ; `WorkflowPass` n'a rien à changer, et les blocs `resource:` du `services.yaml` de l'application disparaissent.

### C4 — Extension procédurale de 819 lignes, sans `Resources/config`, avec des identifiants en FQCN
- **Fichier** : `src/DurableBundle/DependencyInjection/DurableExtension.php:86-115` (l'aiguillage), `src/DurableBundle/Resources/` (ne contient que `views/`)
- **Gravité** : majeur
- **Constat** : toute la définition des services est écrite en PHP impératif dans l'extension (60 `->register(`, 18 `setAlias`), sans aucun fichier de configuration de services ; les identifiants sont les FQCN du cœur (`Gplanchat\Durable\ExecutionEngine`, `:542`) plutôt que des identifiants préfixés `durable.*`. Le prix se voit à `registerDbalRunCatalog()` (`:217-223`) et `registerInMemoryRunCatalog()` (`:276-280`), qui doivent déplacer une définition déjà posée et en supprimer une autre parce que l'ordre d'appel des 18 méthodes de `load()` est devenu la structure du conteneur.
- **Amont** : https://symfony.com/doc/current/bundles/best_practices.html — « If the bundle defines services, they must be prefixed with the bundle alias instead of using fully qualified class names » ; « all services should be defined explicitly » dans `config/`. Cf. `symfony/vendor/symfony/framework-bundle/Resources/config/*.php` (≈40 fichiers) et `symfony/vendor/doctrine/doctrine-bundle/config/messenger.php`. https://symfony.com/doc/current/bundles/extension.html recommande `AbstractBundle` + `loadExtension()` + `$container->import()` pour tout nouveau bundle.
- **Correctif** : sortir le squelette invariant dans `config/services.php` (`ContainerConfigurator`, ids `durable.*`, `alias()` d'interfaces), ne garder dans le code que les branches réellement conditionnelles (dbal / temporal / messenger), et basculer sur `AbstractBundle`, ce qui fusionne `DurableBundle` + `DurableExtension` + `Configuration`.

### C5 — Le bundle s'insère de force en tête de **tous** les bus Messenger de l'application
- **Fichier** : `src/DurableBundle/DependencyInjection/Compiler/RegisterDurableMiddlewarePass.php:38-57`
- **Gravité** : majeur
- **Constat** : la passe itère `findTaggedServiceIds('messenger.bus')` et réécrit le paramètre `<busId>.middleware` de chaque bus, y compris ceux que l'application a définis pour son propre compte, en insérant les middlewares Durable en position 0 (ou 1 derrière `traceable`). Il n'y a ni option de configuration ni liste de bus : le verrou DBAL et le middleware de profil s'appliquent au bus de commandes métier d'un utilisateur qui n'a jamais rien demandé. Le raisonnement du docblock (« il n'existe pas de balise `messenger.middleware` ») est exact, mais la conclusion — s'installer partout — n'est pas celle de l'amont.
- **Amont** : `symfony/vendor/doctrine/doctrine-bundle/config/messenger.php:18` définit `messenger.middleware.doctrine_transaction` et laisse l'application l'ajouter dans `framework.messenger.buses.<bus>.middleware`. Cf. https://symfony.com/doc/current/messenger.html#middleware.
- **Correctif** : publier les middlewares sous des identifiants stables (`durable.messenger.middleware.single_resume_lock`, `…workflow_run_dispatch_profiler`) et documenter leur ajout ; à défaut, ajouter un nœud `durable.messenger.buses: []` limitant la passe aux bus nommés.

### C6 — `activity_contracts.contracts` réénumère à la main ce que le conteneur sait déjà
- **Fichier** : `src/DurableBundle/DependencyInjection/Configuration.php:59-63`, consommé en `DurableExtension.php:516-531`
- **Gravité** : mineur
- **Constat** : le préchauffage du cache exige la liste des interfaces de contrat dans le YAML applicatif (`symfony/config/packages/durable.yaml:19-23`, quatre FQCN écrits à la main). Ces mêmes FQCN sont déjà connus du conteneur : `ActivityHandlerPass.php:44` lit `$tag['contract']` sur chaque service balisé `durable.activity_handler`, balise posée automatiquement par l'attribut. Une interface ajoutée sans être reportée dans le YAML n'est simplement pas préchauffée, sans avertissement.
- **Amont** : https://symfony.com/doc/current/bundles/best_practices.html — la configuration sert « what changes per environment », pas à redire un inventaire dérivable ; c'est le rôle d'une passe de compilation (cf. `MessengerPass` de FrameworkBundle qui découvre les handlers par balise).
- **Correctif** : faire produire la liste par `ActivityHandlerPass` (ou une passe dédiée) et injecter le résultat dans le cache warmer ; garder le nœud `contracts` uniquement comme complément pour les contrats sans gestionnaire enregistré.

### C7 — Le pool de cache configuré est abandonné en silence s'il n'est pas une `Definition`
- **Fichier** : `src/DurableBundle/DependencyInjection/DurableExtension.php:503-505`
- **Gravité** : mineur
- **Constat** : `$container->hasDefinition($cacheId) ? new Reference($cacheId) : null` retourne `false` pour un **alias** (`Psr\Cache\CacheItemPoolInterface` est un alias vers `cache.app` dans `Resources/config/cache.php:268`) et pour tout service défini après le chargement des extensions, par exemple dans le `services.yaml` de l'application. L'utilisateur écrit `activity_contracts.cache: mon.pool`, le résolveur tourne sans cache, et rien ne le signale.
- **Amont** : `symfony/vendor/symfony/framework-bundle/Resources/config/cache.php:268` (`->alias(CacheItemPoolInterface::class, 'cache.app')`) ; la convention Symfony est de passer une `Reference` et de laisser `CheckExceptionOnInvalidReferenceBehaviorPass` valider à la compilation.
- **Correctif** : passer inconditionnellement `new Reference($cacheId)` quand la valeur est non nulle — l'erreur devient une erreur de compilation explicite au lieu d'une dégradation muette.

### C8 — Aucune recette Flex : le « hello workflow » se paie en ~35 lignes de YAML écrites à la main
- **Fichier** : `documentation/user/getting-started/_index.md:52-69` (durable.yaml), `:86-97` (messenger.yaml), `:141-143` (services.yaml) ; défauts dans `src/DurableBundle/DependencyInjection/Configuration.php:16-87`
- **Gravité** : mineur
- **Constat** : Flex enregistre bien le bundle sans recette (auto-génération depuis le namespace PSR-4, `symfony/vendor/symfony/flex/src/Flex.php:706` + `SymfonyBundle.php:64-92`), mais s'arrête là. Or les nœuds `event_store`, `temporal`, `workflow_metadata` et `child_workflow.parent_link_store` du bloc « Minimal Symfony configuration » ne font que réécrire les valeurs déjà posées par `addDefaultsIfNotSet()` — 10 des 18 lignes de `durable.yaml` sont redondantes. Reste le vrai coût : les 12 lignes de transports et de routage Messenger, qu'aucune recette ne pose et qu'aucun `prepend()` ne fournit (`DurableExtension` n'implémente pas `PrependExtensionInterface`).
- **Amont** : https://symfony.com/doc/current/setup/flex.html (recettes et `config/packages/*.yaml` livrés) ; https://symfony.com/doc/current/bundles/prepend_extension.html pour la configuration d'une extension tierce depuis un bundle.
- **Correctif** : soumettre une recette à `symfony/recipes-contrib` livrant un `config/packages/durable.yaml` minimal, et implémenter `prepend()` pour poser le routage `framework.messenger.routing` des cinq messages internes — l'application ne garderait que les DSN de transports.

## Point sain

L'autoconfiguration par attribut (`DurableBundle.php:24-49`) et le pilotage des priorités de passes de compilation (`:52-60`, avec justification écrite de chaque priorité) suivent l'usage idiomatique de `registerAttributeForAutoconfiguration` et de `PassConfig`.
