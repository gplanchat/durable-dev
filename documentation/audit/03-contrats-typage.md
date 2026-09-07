# Contrats d'interface et typage — `Port/`, `Store/`, `Query/`, `Mapping/`, `Attribute/`

## Synthèse
Les quatorze fichiers de `Port/` forment un ensemble globalement cohérent : douze interfaces y sont
réellement doubles (une implémentation journal, une implémentation Temporal), les docblocs y sont
denses et justifient les choix, et `WorkflowRunCatalogInterface` montre ce que le dépôt sait faire de
mieux — un port qui rend des objets de valeur (`WorkflowRunPage`, `WorkflowRunEvent`) plutôt que des
tableaux. Les défauts se concentrent ailleurs : un contrat de rejeu qui confond « rien d'enregistré »
et « null enregistré », un port de commandes dont un tiers des méthodes n'est honorable que par un
seul des deux backends, une interface à implémentation unique que personne n'injecte, et un
`NullEventStore` qui n'est pas un objet nul mais un bouche-trou de signature. Côté typage, les formes
de tableaux sont abondamment déclarées en PHPDoc mais `phpstan.neon` est réglé sur `level: 5`, palier
auquel ni les types de valeurs d'itérables ni les `mixed` ne sont vérifiés : ces annotations sont de
la documentation, pas des contraintes. Sur deux axes le périmètre est sain — les attributs de
`Attribute/` sont corrects et typés `class-string` là où il faut, et `Query/` comme
`Mapping/EventDataMapper` déclarent des formes de retour exactes et cohérentes avec ce qu'ils
produisent.

## Constats

### C1 — `findSideEffectForSlot()` confond « pas enregistré » et « enregistré à `null` »
- **Fichier** : `src/Durable/Port/WorkflowHistorySourceInterface.php:86`
- **Gravité** : majeur
- **Constat** : la méthode rend `mixed` et documente « null if not yet recorded » ; les deux
  implémentations rendent bien `null` dans les deux cas
  (`src/Durable/Store/EventStoreHistorySource.php:229` puis `:235`,
  `src/Bridge/Temporal/Worker/TemporalExecutionHistory.php:567`). Le consommateur teste
  `null !== $replayResult` (`src/Durable/ExecutionContext.php:316`) : une closure `sideEffect()` qui
  rend `null` est donc **ré-exécutée à chaque rejeu**, et `recordSideEffect()`
  (`src/Durable/Store/EventStoreCommandBuffer.php:97`) réémet un `SideEffectRecorded` à chaque passe.
  J'ai vérifié que ces ajouts atterrissent en fin de flux, donc l'indexation des autres créneaux
  reste alignée ; ce qui reste est la perte de déterminisme sur une closure non déterministe et une
  croissance non bornée du journal — sur Temporal, un `RECORD_MARKER` de plus par tâche de workflow.
  Les trois méthodes sœurs du même port (`findActivitySlotResult`, `findChildWorkflowForSlot`,
  `findNexusOperationSlotResult`, lignes 25, 93, 60) enveloppent justement leur résultat dans une
  forme `array{result: mixed, ...}` pour éviter exactement cela : l'incohérence est interne au
  contrat.
- **Amont** : non sourcé — défaut démontrable depuis le code et ses deux implémentations, pas une
  violation de convention amont.
- **Correctif** : aligner la signature sur ses sœurs, `findSideEffectForSlot(int $slot): ?array` avec
  la forme `array{result: mixed}|null`, et adapter le test de `ExecutionContext::sideEffect()`.

### C2 — `WorkflowCommandBufferInterface` : 5 méthodes sur 14 ne sont honorables que par un backend
- **Fichier** : `src/Durable/Port/WorkflowCommandBufferInterface.php:35`
- **Gravité** : majeur
- **Constat** : `recordUpdateHandled`, `completeChildWorkflow` et `failChildWorkflow` ont un corps
  vide côté Temporal (`src/Bridge/Temporal/Worker/TemporalWorkflowCommandBuffer.php:310`, `:356`,
  `:362`), et symétriquement `scheduleNexusOperation` et `cancelNexusOperation` lèvent
  `NexusUnsupportedByBackendException` côté journal
  (`src/Durable/Store/EventStoreCommandBuffer.php:209` et `:214`). Un tiers du contrat est donc
  inapplicable selon l'implémentation, et l'interface le documente elle-même comme intentionnel
  (`:69`, `:98`, `:128`) — ce qui en fait un contrat déclaré non honorable plutôt qu'un oubli.
- **Amont** : `api-platform/core` sépare l'état en deux contrats d'une seule méthode,
  `src/State/ProviderInterface.php` et `src/State/ProcessorInterface.php`, plutôt qu'en un contrat
  large dont chaque implémentation ne remplirait qu'une partie
  (https://github.com/api-platform/core/blob/main/src/State/ProviderInterface.php).
- **Correctif** : extraire les capacités facultatives en interfaces séparées
  (`NexusCommandBufferInterface`, `InlineChildWorkflowRecorderInterface`) que l'appelant teste par
  `instanceof`, au lieu d'un `@throws` documentant qu'une implémentation conforme peut refuser.

### C3 — `WorkflowBackendInterface` : une seule implémentation, aucun consommateur, et un cycle
- **Fichier** : `src/Durable/Port/WorkflowBackendInterface.php:17`
- **Gravité** : majeur
- **Constat** : recensement sur `src/` — la seule implémentation est
  `src/Durable/Port/LocalWorkflowBackend.php:18`, et le seul site qui mentionne l'interface est son
  enregistrement DI (`src/DurableBundle/DependencyInjection/DurableExtension.php:607`) ; aucune
  classe ne la reçoit en injection. Le backend Temporal, que le docbloc annonce comme motif
  (`:11-12`), ne l'implémente pas : il passe par `WorkflowTaskRunner`, d'une tout autre forme. En
  outre `LocalWorkflowBackend.php:7` importe `Gplanchat\Durable\ExecutionEngine`, qui importe
  lui-même `Gplanchat\Durable\Port\ChildWorkflowRunnerInterface`
  (`src/Durable/ExecutionEngine.php:10`) : le namespace censé isoler le cœur des backends dépend du
  moteur concret.
- **Amont** : `symfony/contracts` ne publie que des interfaces et des traits sans dépendance vers les
  composants qui les implémentent (https://github.com/symfony/contracts) — la direction de
  dépendance va de l'implémentation vers le contrat, jamais l'inverse.
- **Correctif** : supprimer `WorkflowBackendInterface` et `LocalWorkflowBackend` — `ExecutionEngine`
  est déjà la surface publique de démarrage — ou, si le port doit vivre, déplacer
  `LocalWorkflowBackend` hors de `Port/` et le faire implémenter par le pont Temporal.

### C4 — `NullEventStore` n'est pas un objet nul, c'est un bouche-trou de signature
- **Fichier** : `src/Durable/Store/NullEventStore.php:44`
- **Gravité** : majeur
- **Constat** : son propre docbloc l'annonce — « used when an EventStoreInterface is required by
  signature but the call site operates in distributed mode … where EventStoreInterface methods are
  never actually called ». Son unique usage
  (`src/Bridge/Temporal/Worker/WorkflowTaskRunner.php:45`) le passe à `ExecutionRuntime` à côté d'un
  `NoopActivityTransport()` : c'est le constructeur d'`ExecutionRuntime` qui sur-spécifie ses
  dépendances pour la moitié des backends supportés. Si l'hypothèse « jamais appelées » venait à
  tomber, `readStream()` rendant `[]` est indistinguable d'une exécution neuve, et le workflow
  rejouerait ses activités au lieu d'échouer. Les deux autres `Null*` du périmètre —
  `Port/NullWorkflowTimerDispatcher.php:11` et `Port/NullWorkflowResumeDispatcher.php:10` — sont en
  revanche des objets nuls corrects : ne rien faire y est un comportement réel pour un hôte mono-
  processus, et les deux le disent.
- **Amont** : `Psr\Log\NullLogger` est l'objet nul de référence parce que journaliser est advisory ;
  un store qui *est* la garantie de durabilité ne rentre pas dans ce moule
  (https://www.php-fig.org/psr/psr-3/).
- **Correctif** : rendre la dépendance `EventStoreInterface` d'`ExecutionRuntime` explicitement
  nullable pour le chemin Temporal, ou faire lever `NullEventStore` sur toute méthode plutôt que
  rendre un flux vide silencieux.

### C5 — `level: 5` : les formes de tableaux déclarées dans les ports ne sont pas vérifiées
- **Fichier** : `phpstan.neon:5`
- **Gravité** : majeur
- **Constat** : les ports déclarent une quinzaine de formes précises — `array{result: mixed, failed:
  \Throwable|null}|null` (`Port/WorkflowHistorySourceInterface.php:23`), `array{position: int, kind:
  'signal'|'update', …}` (`:128`), `array{workflowType: string, payload: array<string, mixed>,
  completed?: bool}|null` (`Store/WorkflowMetadataStore.php:94`) — mais les types de valeurs
  d'itérables ne sont contrôlés qu'à partir du niveau 6 et les `mixed` stricts qu'au niveau 9. Les
  casts `(string) $payload['activityId']` sur du `mixed` dans
  `src/Durable/Mapping/EventDataMapper.php:84,91,94,98` passent donc sans signalement, alors que
  c'est précisément la frontière de désérialisation où une forme fausse doit être rejetée. Le
  `completed?: bool` optionnel de `WorkflowMetadataStore::get()` recouvre par ailleurs
  `hasActiveWorkflowMetadata()` (`:101`) sans que rien n'exprime laquelle des deux fait foi.
- **Amont** : niveaux PHPStan et types documentés
  (https://phpstan.org/user-guide/rule-levels, https://phpstan.org/writing-php-code/phpdoc-types) ;
  `api-platform/core` double ses `@param` d'un `@psalm-param array{…}` pour que la forme soit
  effectivement contrainte.
- **Correctif** : monter `src/Durable` au niveau 6 puis 8 avec une baseline, et remplacer les formes
  de retour des ports par des objets de valeur readonly comme le fait déjà
  `WorkflowRunCatalogInterface`.

### C6 — `WorkflowHistorySourceInterface` : `scheduledAt` annoncé en `Duration`, déclaré `float`, toujours `0.0`, jamais lu
- **Fichier** : `src/Durable/Port/WorkflowHistorySourceInterface.php:74`
- **Gravité** : mineur
- **Constat** : le docbloc de classe affirme (`:10`) que « Recorded timings are returned as
  `Duration` » et prévient les implémentations tierces du changement, mais `findTimerSlotResult()`
  déclare toujours `array{id: string, scheduledAt: float, failed: \Throwable|null}|null`.
  `EventStoreHistorySource.php:195` et `:205` y écrivent `0.0` en dur, et une recherche sur
  `['scheduledAt']` dans `src/` ne trouve aucun lecteur de cette clé. Une clé de forme que personne
  ne produit ni ne consomme reste néanmoins obligatoire pour toute implémentation tierce.
- **Amont** : non sourcé — contradiction interne entre le docbloc de classe et la signature, vérifiée
  sur les deux implémentations.
- **Correctif** : retirer `scheduledAt` de la forme de retour, ou la typer `Duration` et la faire
  réellement porter la durée enregistrée dans les deux implémentations.

### C7 — Suffixe `Interface` : trois exceptions dans un ensemble qui l'applique partout ailleurs
- **Fichier** : `src/Durable/Port/WorkflowResumeDispatcher.php:12`
- **Gravité** : mineur
- **Constat** : `WorkflowResumeDispatcher` (4 implémentations), `WorkflowTimerDispatcher`
  (`src/Durable/Port/WorkflowTimerDispatcher.php:22`, 3 implémentations) et `WorkflowMetadataStore`
  (`src/Durable/Store/WorkflowMetadataStore.php:81`) sont des interfaces sans suffixe, quand les
  douze autres de `Port/` et les deux autres de `Store/` l'ont. Le coût est concret :
  `NullWorkflowResumeDispatcher implements WorkflowResumeDispatcher` se lit comme une extension de
  classe.
- **Amont** : standards de code Symfony, « Suffix interfaces with `Interface` »
  (https://symfony.com/doc/current/contributing/code/standards.html).
- **Correctif** : renommer les trois en `*Interface` avec un alias `class_alias` déprécié le temps
  d'une version majeure, conformément à la règle du dépôt sur les BC.

### C8 — Trois docblocs orphelins : la documentation s'attache à la mauvaise méthode
- **Fichier** : `src/Durable/Port/WorkflowCommandBufferInterface.php:107`
- **Gravité** : mineur
- **Constat** : trois sites empilent deux docblocs consécutifs ; PHP n'associe que le dernier, le
  premier est perdu et sa méthode se retrouve sans documentation. Ici le docbloc « Records workflow
  failure (COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION) » précède celui de `recordVersion()` alors qu'il
  décrit `failWorkflow()` (`:118`) ; même schéma à `:120` où la doc de `cancelActivity()` (`:147`)
  précède celle de `scheduleNexusOperation()` ; et à
  `src/Durable/Port/WorkflowLifecycleInterface.php:42`, où la doc d'`onCancelled()` (`:59`) précède
  celle d'`onCancellationDelivered()`. Les IDE et tout outil lisant `getDocComment()` affichent donc
  la mauvaise sémantique sur trois méthodes d'annulation et d'échec.
- **Amont** : non sourcé — comportement de `ReflectionMethod::getDocComment()`, qui ne retourne que
  le commentaire immédiatement précédent.
- **Correctif** : déplacer chacun des trois docblocs orphelins au-dessus de la méthode qu'il décrit.

## Axes sains
- **`Attribute/`** : les douze attributs sont corrects — cibles `TARGET_CLASS`/`TARGET_METHOD`
  justes, `IS_REPEATABLE` sur le seul qui en a besoin (`FulfilsNexusOperation.php:215`), et
  `class-string` sur les trois paramètres qui portent un contrat. Deux détails de cohérence non
  bloquants : la moitié sont `final readonly class` et l'autre `final class` à propriétés
  `readonly` ; et seuls `AsSignalMethod`/`AsUpdateMethod` acceptent `\BackedEnum|string` là où
  `AsQueryMethod`/`AsActivityMethod` exigent `string`.
- **`Query/`** : `WorkflowQueryEvaluator` et sa façade `WorkflowQueryRunner` déclarent des `list<…>`
  exacts et cohérents avec ce qu'ils construisent ; rien à redire.
- **`Mapping/EventDataMapper`** : les cinq types `NexusOperation*` absents du `match` de
  `toDomainEvent()` sont une exclusion écrite et couverte par un point d'extension de conformité
  (`src/Durable/Testing/EventStoreConformanceTestCase.php:363`), pas un oubli. Le seul reproche est
  que « événement journalisable » n'est exprimé que par ce tableau protégé, et pas au niveau du type.
- **Recensement des implémentations** : sur les douze interfaces de `Port/`, dix en ont au moins deux
  réelles. Les exceptions sont `WorkflowBackendInterface` (constat C3),
  `ParentChildWorkflowCoordinatorInterface` (une seule, `src/Durable/ParentChildWorkflowCoordinator.php`,
  mais injectée par `ExecutionEngine.php:11` — abstraction défendable) et
  `DeclaredActivityFailureInterface` (aucune dans `src/`, ce qui est normal : c'est un point
  d'extension utilisateur, consommé par cinq classes du cœur).
