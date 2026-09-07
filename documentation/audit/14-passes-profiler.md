# Compiler passes et profiler — collecte, sérialisation des données collectées

## Synthèse
Le périmètre est fonctionnel mais s'écarte de deux conventions structurantes de `symfony/http-kernel` : les données collectées ne passent jamais par `cloneVar()`, et la trace qui les alimente tourne dans tous les environnements sans être réinitialisable par le `services_resetter`. Le premier point rend le profiler dépendant de la sérialisabilité des charges utiles applicatives ; le second fait grandir un tableau en mémoire pour la durée de vie d'un worker `messenger:consume`, en production. Les cinq passes de compilation sont correctement ordonnées (priorité 50 après `ResolveInstanceofConditionalsPass`, priorité 10 avant `MessengerPass`) et `NexusHandlerPass` est exemplaire sur le refus au démarrage, mais aucune n'utilise le second argument `$throwOnAbstract` de `findTaggedServiceIds()` et `ActivityHandlerPass` avale en silence une balise mal formée là où sa jumelle Nexus lève. Le template Twig est sain sur les deux axes demandés : aucun `|raw`, aucun `<script>` interpolé, `json_encode` n'étant pas déclaré `is_safe` côté Twig (`vendor/twig/twig/src/Extension/CoreExtension.php:251`) les charges utiles sont bien ré-échappées en HTML, et l'état vide est traité aux lignes 230, 299 et 521. `TemporalEventConverter` est un convertisseur sans état partagé entre exécutions, correctement documenté comme tel.

## Constats

### C1 — Les données collectées ne sont jamais `cloneVar`-ées : un objet non sérialisable dans une charge utile casse tout le profiler
- **Fichier** : `src/DurableBundle/DataCollector/DurableDataCollector.php:643` (`'payload' => $event->payload()`), `:95` (affectation de `$this->data`), `:842` (`__serialize()` renvoie le tableau brut)
- **Gravité** : bloquant
- **Constat** : `Event::payload()` est typé `array<string, mixed>` (`src/Durable/Event/Event.php:14`) et `DurableExecutionTrace::onWorkflowDispatchRequested()` stocke le payload de dispatch tel quel (`src/DurableBundle/Profiler/DurableExecutionTrace.php:47`). Ces valeurs arrivent intactes dans `$this->data` puis dans `__serialize()`, qui les rend au `Profiler` pour écriture. Une charge utile portant une `Closure`, une ressource ou un `PDO` fait échouer la sérialisation du profil entier — pas seulement le panneau Durable. La classe n'appelle `cloneVar()` nulle part (`grep` sur le fichier : zéro occurrence).
- **Amont** : `symfony/http-kernel` `DataCollector/DataCollector.php` — `cloneVar()` instancie un `VarCloner` avec `getCasters()`, qui enveloppe tout objet non-`DateTimeInterface` dans un `CutStub` précisément pour rendre `$data` sérialisable ; `symfony/messenger` `DataCollector/MessengerDataCollector::lateCollect()` fait `$debugRepresentation = $this->cloneVar($this->collectMessage($busName, $message));`.
- **Correctif** : passer par `$this->cloneVar()` sur tout ce qui vient d'un payload applicatif (`payload` des lignes 643, `'payload'` des entrées de timeline, `metadata.payload`), et supprimer l'`__serialize()`/`__unserialize()` maison au profit du `__sleep()` hérité — la classe parente de 7.3 ne déclare pas `__serialize()`, contrairement à ce qu'affirme le commentaire de `:837`.

### C2 — La trace profiler est instanciée et alimentée en production, sans `kernel.reset` : fuite mémoire dans un worker
- **Fichier** : `src/DurableBundle/DependencyInjection/DurableExtension.php:96` (`registerProfiler()` appelé sans condition), `:744-748`, `src/DurableBundle/EventListener/ResetDurableProfilerListener.php:24`
- **Gravité** : bloquant
- **Constat** : `durable.execution_trace` est aliasé sur `WorkflowExecutionObserverInterface` et injecté sans condition dans `ActivityMessageProcessor` et deux autres services (`DurableExtension.php:479, 550, 716`) ; le middleware `WorkflowRunDispatchProfilerMiddleware` est posé sur tous les bus. `DurableExecutionTrace::$timeline` grandit donc d'une entrée par run et par activité, en prod comme en dev. Le seul reset est branché sur `KernelEvents::REQUEST` : dans `messenger:consume`, aucun `kernel.request` ne survient, le tableau grandit pour la durée de vie du process.
- **Amont** : `symfony/http-kernel` `DependencyInjection/ResettableServicePass.php:34` (tag `kernel.reset`, consommé avec `findTaggedServiceIds('kernel.reset', true)`) et `symfony/messenger` `EventListener/ResetServicesListener.php`, qui appelle `services_resetter` à chaque `WorkerMessageHandled`/`WorkerRunning`.
- **Correctif** : faire implémenter `Symfony\Contracts\Service\ResetInterface` à `DurableExecutionTrace` et lui poser `->addTag('kernel.reset', ['method' => 'reset'])` ; conditionner tout `registerProfiler()` à `%kernel.debug%` (ou substituer un observateur no-op hors debug) pour que la trace ne coûte rien en prod.

### C3 — `durable.activity_handler` sans attribut `contract` est ignoré en silence
- **Fichier** : `src/DurableBundle/DependencyInjection/Compiler/ActivityHandlerPass.php:44-47`
- **Gravité** : majeur
- **Constat** : `if (!\is_string($contract) || '' === $contract) { continue; }` — une balise posée à la main en YAML sans `contract`, ou avec une clé mal orthographiée, ne produit aucun message : le service est simplement jamais enregistré sur `ActivityExecutor`, et la faute ne réapparaît qu'au runtime sous la forme d'une activité inconnue. `NexusHandlerPass:66-72` traite exactement le même cas en levant une `LogicException` nommant le service, et l'argumentaire de son en-tête (`:26-30`) vaut mot pour mot ici.
- **Amont** : `vendor/symfony/messenger/DependencyInjection/MessengerPass.php` lève `InvalidArgumentException` sur un attribut de balise invalide plutôt que d'ignorer le service ; même politique dans `symfony/event-dispatcher` `RegisterListenersPass`.
- **Correctif** : remplacer le `continue` par une `LogicException` calquée sur celle de `NexusHandlerPass:67`. Idem pour les retours anticipés silencieux de `:23-25` (pas d'`ActivityExecutor` alors que des handlers sont taggés) et `WorkflowPass:18`.

### C4 — Le journal de chaque exécution est relu trois fois par requête, sur une liste d'identifiants pilotée par la query string
- **Fichier** : `src/DurableBundle/DataCollector/DurableDataCollector.php:215`, `:478`, `:628`, alimentés par `mergeExecutionIdsFromRequest()` `:447`
- **Gravité** : majeur
- **Constat** : `collect()` appelle `readStreamWithRecordedAt()` une fois dans `buildStoreTimelines()` (`:478`), une fois dans `collectStoreEventRows()` (`:628`) et une troisième dans `resolveExecutionStatus()` (`:215`), plus `countEventsInStream()` aux lignes 74, 168, 212 et 432. Seules les deux premières sont plafonnées par `MAX_STORE_EVENTS_PER_STREAM` ; la boucle de `:215` parcourt le flux entier juste pour retenir le dernier événement. Et `?durable_execution=a,b,c,…` accepte une liste non bornée d'identifiants, chacun déclenchant ce triple parcours.
- **Amont** : `symfony/messenger` `MessengerDataCollector` implémente `LateDataCollectorInterface` et laisse `collect()` vide (« Noop. Everything is collected live by the traceable buses ») ; le travail lourd est repoussé hors du cycle de la réponse.
- **Correctif** : lire chaque flux une seule fois, mémoriser les entrées, et dériver statut/timeline/lignes de ce tampon ; borner le nombre d'identifiants acceptés depuis `durable_execution` et déplacer la collecte dans `lateCollect()`.

### C5 — Aucune des cinq passes n'utilise le second argument `$throwOnAbstract` de `findTaggedServiceIds()`
- **Fichier** : `src/DurableBundle/DependencyInjection/Compiler/ActivityHandlerPass.php:22`, `NexusHandlerPass.php:46` et `:194`, `RegisterDurableMiddlewarePass.php:38` et `:66`, `WorkflowPass.php:24`
- **Gravité** : mineur
- **Constat** : la signature est `findTaggedServiceIds(string $name, bool $throwOnAbstract = false)` (`vendor/symfony/dependency-injection/ContainerBuilder.php:1364`), et le second argument produit « The service "%s" tagged "%s" must not be abstract. » Sans lui, une définition abstraite taggée est traitée normalement, un `new Reference($serviceId)` est posé dessus, et l'échec survient plus tard sous forme d'un `ServiceNotFoundException` obscur après `RemoveAbstractDefinitionsPass`.
- **Amont** : `vendor/symfony/messenger/DependencyInjection/MessengerPass.php:62`, `vendor/symfony/framework-bundle/DependencyInjection/Compiler/ProfilerPass.php:37`, `vendor/symfony/http-kernel/DependencyInjection/ResettableServicePass.php:34` — la convention amont est systématique sur les passes qui instancient le service taggé.
- **Correctif** : passer `true` sur les cinq appels qui référencent ensuite le service (tous sauf éventuellement `messenger.bus`, qui n'instancie rien).

### C6 — `WorkflowPass` utilise `getDefinition()` là où les autres passes utilisent `findDefinition()`
- **Fichier** : `src/DurableBundle/DependencyInjection/Compiler/WorkflowPass.php:25`
- **Gravité** : mineur
- **Constat** : `$container->getDefinition($id)` lève `ServiceNotFoundException` si l'identifiant taggé est un alias, alors que `ActivityHandlerPass:53` et `NexusHandlerPass:74` passent par `findDefinition()`, qui résout les alias. Le filtre `if (!str_contains($class, '\\')) { continue; }` de `:27` écarte de plus, sans un mot, tout workflow déclaré dans l'espace de noms global.
- **Amont** : `vendor/symfony/dependency-injection/ContainerBuilder.php` — `findDefinition()` est documenté comme la variante qui suit les alias ; c'est la forme employée dans les passes du framework qui itèrent sur des balises.
- **Correctif** : remplacer par `findDefinition()` et lever plutôt que `continue` quand la classe résolue n'est pas un FQCN chargeable.

### C7 — `json_encode` sans `JSON_INVALID_UTF8_SUBSTITUTE` : une charge utile binaire s'affiche comme un vide
- **Fichier** : `src/DurableBundle/Resources/views/Collector/durable.html.twig:282`, `:369`, `:441`
- **Gravité** : mineur
- **Constat** : les trois `<pre>` font `{{ … |json_encode(128) }}` (128 = `JSON_PRETTY_PRINT`). Une chaîne non-UTF-8 dans un payload — un blob, un identifiant binaire — fait renvoyer `false` à `json_encode`, et le bloc s'affiche vide sans indiquer que quelque chose a été perdu. Le résumé côté PHP, lui, utilise bien `JSON_THROW_ON_ERROR` et retombe sur `'…'` (`DurableDataCollector.php:368-371`).
- **Amont** : non sourcé (usage général de `json_encode` ; les collectors amont n'ont pas ce problème puisqu'ils rendent un `Data` via `profiler_dump`).
- **Correctif** : une fois C1 corrigé, rendre ces blocs avec `{{ profiler_dump(collector.…) }}` sur la donnée `cloneVar`-ée, ce qui supprime le passage par `json_encode` et gère nativement les valeurs non représentables.

### C8 — `getTemplate()` statique mort et `__serialize()` justifié par un commentaire inexact
- **Fichier** : `src/DurableBundle/DataCollector/DurableDataCollector.php:825-828` et `:837-843`
- **Gravité** : remarque
- **Constat** : le template est déjà déclaré sur la balise `data_collector` (`DurableExtension.php:770-773`), qui est la seule source lue par `WebProfilerBundle` ; `getTemplate()` n'est appelé nulle part dans le dépôt. Le commentaire de `:837` affirme que « le DataCollector de Symfony ne déclare `__serialize()` qu'à partir de 7.0 » : la classe amont ne le déclare toujours pas en 7.3, elle utilise `__sleep(): array { return ['data']; }`.
- **Amont** : `symfony/http-kernel` `DataCollector/DataCollector.php` (7.3) — `__sleep()` lignes 83-85, `__wakeup()` 87-88, `serialize()`/`unserialize()` finaux et vides ; aucun `__serialize()`.
- **Correctif** : supprimer `getTemplate()` et l'override `__serialize()`/`__unserialize()`, dont l'effet est identique au `__sleep()` hérité.
