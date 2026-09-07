# PHP moderne et Fibers — rejeu et coût du chemin chaud

## Synthèse
Le modèle de suspension est globalement juste : un seul `Fiber::suspend()` dans tout le cœur (`ExecutionRuntime:68`), un pilote unique (`WorkflowFiberDriver`), et la livraison d'annulation par `Fiber::throw()` au point de suspension est conforme à la sémantique de la RFC. Deux angles morts subsistent côté Fibers : le fiber abandonné à chaque suspension est *détruit*, ce qui exécute les blocs `finally` du workflow à chaque passe, et une valeur de suspension inattendue fait sortir le pilote sans aucun rappel de cycle de vie. Le rejeu déterministe est bien gardé (slots positionnels, garde de divergence, versioning déduit), sauf sur `sideEffect()` dont le sentinelle de « non enregistré » est `null` — donc un effet de bord qui rend `null` se rejoue pour de vrai à chaque replay. Le coût du chemin chaud est le point le plus lourd : `EventStoreHistorySource` relit tout le flux à chaque interrogation, sans aucune mémoïsation, ce qui rend une passe de rejeu quadratique et, sous DBAL, quadratique en requêtes SQL. Sur l'axe PHP 8.2+ le périmètre est sain : 69 `readonly class`, 222 propriétés `readonly`, 10 `enum`, `match(true)`, types de propriété partout — rien à signaler.

## Constats

### C1 — `sideEffect()` se rejoue pour de vrai quand la valeur enregistrée est `null`
- **Fichier** : `src/Durable/ExecutionContext.php:316` (avec `src/Durable/Store/EventStoreHistorySource.php:223-236`)
- **Gravité** : bloquant
- **Constat** : `findSideEffectForSlot()` rend `null` aussi bien pour « pas de slot enregistré » que pour « slot enregistré valant `null` ». Le test `if (null !== $replayResult)` ligne 316 conclut donc « non enregistré », **ré-exécute la closure non déterministe** et appende un `SideEffectRecorded` de plus (`ExecutionContext:323`). À chaque passe de rejeu : effet de bord rejoué, valeur potentiellement différente, et journal qui croît d'un événement par passe. C'est exactement la propriété que `sideEffect()` existe pour garantir. Le trou est déjà écrit dans le docblock de `hasRecordedWorkAhead()` (`ExecutionContext:243-246`) pour le versioning, mais pas corrigé à sa source.
- **Amont** : Temporal encode la présence d'un side effect par l'existence du marqueur, jamais par sa valeur — `MarkerRecorded` / `SideEffectMarkerName` (https://github.com/temporalio/sdk-go/blob/master/internal/internal_event_handlers.go, `SideEffectMarkerName`) ; la doc citée par `SideEffectRecorded` elle-même (https://docs.temporal.io/develop/php/side-effects) pose que la closure n'est pas rejouée.
- **Correctif** : ajouter `hasSideEffectForSlot(int): bool` au port, ou faire rendre à `findSideEffectForSlot()` un `?array{value: mixed}` — la présence du slot ne doit jamais être déduite de la valeur.

### C2 — Le rejeu est quadratique : `EventStoreHistorySource` relit tout le flux à chaque interrogation
- **Fichier** : `src/Durable/Store/EventStoreHistorySource.php:44` (15 appels à `readStream()` dans le fichier, aucun champ de cache)
- **Gravité** : majeur
- **Constat** : chaque méthode du port ouvre son propre `readStream()` sur la totalité du flux. `ExecutionContext::activity()` en déclenche trois par activité (`activityNameForSlot`, `findActivitySlotResult`, `findScheduledActivityId`) et `findActivitySlotResult()` alloue cinq tableaux à chaque appel pour n'en lire qu'un index. `ExecutionContext::countRecordedMessages()` (`:460`) boucle sur `messageAt($n)`, chacun étant lui-même un balayage complet. Un rejeu de N opérations coûte donc O(N²) événements désérialisés. Sous `DbalEventStore::readStreamWithRecordedAt()` (`src/Bridge/Dbal/Store/DbalEventStore.php:58`) chaque balayage est **une requête SQL + un `EventDataMapper::toDomainEvent()` par ligne** : des centaines d'allers-retours base par tâche de workflow.
- **Amont** : les SDK Temporal construisent l'état de rejeu en **une seule passe ordonnée** sur l'historique (state machine alimentée par `HandleHistoryEvent`, https://github.com/temporalio/sdk-go/blob/master/internal/internal_task_handlers.go) ; la source d'historique n'y est jamais ré-interrogée par slot.
- **Correctif** : matérialiser le flux une fois par instance de `EventStoreHistorySource` (elle est déjà liée à une exécution et à une passe) dans des index par slot construits en une passe, et servir les 15 accesseurs depuis ces index.

### C3 — Le fiber abandonné à chaque suspension exécute les `finally` du workflow
- **Fichier** : `src/Durable/Worker/WorkflowFiberDriver.php:89-91`
- **Gravité** : majeur
- **Constat** : sur commande nouvelle, le pilote appelle `onSuspended()` puis `return null` ; la variable locale `$fiber` meurt et PHP détruit un fiber suspendu en **déroulant sa pile**. Vérifié sur PHP 8.2.33 : un `try { await(...) } finally { … }` dans le code workflow voit son `finally` s'exécuter à chaque passe de suspension, et un `finally` qui attend quoi que ce soit prend `FiberError: Cannot suspend in a force-closed fiber` — levé depuis la destruction, donc hors de `dispatchThrowable()` (`:114`) et hors de tout `WorkflowLifecycleInterface`. Aucun ADR ni test ne couvre le cas (`grep finally` vide dans DUR003/DUR027) ; `documentation/user/comparison/_index.md:392` ne mentionne que le cas destructeur.
- **Amont** : RFC Fibers, section Fiber lifecycle — « Fibers that are not finished (do not complete execution) are destroyed similarly to unfinished generators, executing any pending `finally` blocks » et « `Fiber::suspend()` may not be invoked in a force-closed fiber » (https://wiki.php.net/rfc/fibers).
- **Correctif** : écrire l'invariant dans DUR003 (« un `finally` autour d'un `await()` s'exécute une fois par passe, jamais une fois par exécution ») et l'outiller — `src/DurablePhpstan/` a déjà la place pour une règle refusant `try/finally` autour d'un `await()` dans une méthode `#[WorkflowMethod]`.

### C4 — Une valeur de suspension non reconnue abandonne le run en silence
- **Fichier** : `src/Durable/Worker/WorkflowFiberDriver.php:63-65`
- **Gravité** : majeur
- **Constat** : si le fiber suspend avec autre chose qu'un `Awaitable` — code tiers, bibliothèque à fibers appelée depuis une activité en ligne, `Fiber::suspend()` direct que DUR022 interdit sans le vérifier — le `break` sort de la boucle, `isTerminated()` est faux ligne 104, et `run()` rend `null` après n'avoir appelé que `onBeforeRun()`. Ni `onSuspended`, ni `onFailed`, ni `onCompleted` : l'exécution reste « non complétée » dans le `WorkflowMetadataStore` et rien ne la reprogramme. C'est le mode de panne le plus coûteux à diagnostiquer de ce moteur, et il est atteint par un `break`.
- **Amont** : non sourcé (règle interne : `documentation/adr/DUR022-workflow-class-interface-and-workflow-environment.md:21` interdit `Fiber::suspend()` en code workflow, sans garde à l'exécution).
- **Correctif** : remplacer le `break` par `$this->dispatchThrowable($executionId, new WorkflowTaskFailure(...))` nommant la valeur reçue — un run doit toujours sortir du pilote par un rappel de cycle de vie.

### C5 — `ExecutionRuntime` attend par défaut en tournant à vide, sans budget
- **Fichier** : `src/Durable/ExecutionRuntime.php:51` et `:79-82`
- **Gravité** : mineur
- **Constat** : `$distributed` vaut `false` par défaut, donc `new ExecutionRuntime($store, $transport, $executor)` (cas de `tests/integration/Durable/Messenger/MessengerActivityTransportTest.php:41`) prend le chemin `while (!$awaitable->isSettled()) { drain(); checkTimers(); }` : aucune pause, aucun budget, et `checkTimers()` relit tout le flux à chaque tour. Une attente de 30 s brûle un cœur pendant 30 s ; une condition qui attend un signal ne se règle jamais et la boucle ne se termine pas. `runUntilIdle()` (`:196`), lui, a bien un budget — l'asymétrie est fortuite.
- **Amont** : `Symfony\Component\Messenger\Worker` dort (option `sleep`, 1 000 000 µs par défaut) dès qu'aucun message n'est traité, précisément pour ne pas tourner à vide (https://github.com/symfony/messenger/blob/7.2/Worker.php).
- **Correctif** : faire de `distributed = true` le défaut, et border le drain synchrone du même budget que `runUntilIdle()` avec un `usleep()` quand ni activité ni minuteur n'a progressé.

### C6 — Quatre balayages complets du flux par tic de minuterie, et le tic se réarme
- **Fichier** : `src/Durable/Handler/FireWorkflowTimersHandler.php:44-46` et `:57`
- **Gravité** : mineur
- **Constat** : une passe fait `countTimerCompleted()` (balayage), `checkTimers()` (balayage, `ExecutionRuntime:93`), `countTimerCompleted()` (balayage) puis `TimerWakeDelayCalculator` (balayage) — quatre lectures intégrales de l'historique. Quand aucun minuteur n'a tiré, le handler se redispatche (`:64`), donc le coût se répète à chaque réveil, sur un historique qui ne fait que grandir.
- **Amont** : non sourcé (constat de coût direct sur `DbalEventStore::readStream`, une requête SQL par balayage).
- **Correctif** : lire le flux une fois dans `__invoke()` et passer la liste d'événements aux trois calculs, qui n'ont besoin que des `Timer*` — ou faire rendre à `checkTimers()` le nombre de minuteurs réglés, ce qui supprime les deux `countTimerCompleted()`.

### C7 — Réflexion allouée sur le chemin chaud de chaque `await()`
- **Fichier** : `src/Durable/WorkflowEnvironment.php:174` et `:193` (avec `src/Durable/Awaitable/ConditionAwaitable.php:49`)
- **Gravité** : mineur
- **Constat** : `applyMessagesUntil()` ligne 193 appelle `describeCondition()` seulement pour tester la présence d'une condition, mais `ConditionAwaitable::describe()` construit une `ReflectionFunction` et formate une chaîne `sprintf` à chaque fois, résultat jeté. Ligne 174, `self::describe($awaitable)` est évalué en argument à chaque `await()` borné — donc une `ReflectionClass` dans la branche `default` — alors que la chaîne ne sert qu'à composer le message de `DeadlineExceededException`, sur le seul chemin d'échéance dépassée.
- **Amont** : non sourcé (règle générale : la réflexion ne sert pas à un prédicat qui a une réponse structurelle).
- **Correctif** : ajouter `AwaitableInspector::hasCondition(): bool` (même traversée, pas de réflexion) pour la ligne 193, et passer la description en `\Closure` paresseuse — ou l'awaitable lui-même — à `awaitUnderDeadline()`.

### C8 — Le test de contexte fiber n'est pas une identité
- **Fichier** : `src/Durable/ExecutionRuntime.php:67`
- **Gravité** : remarque
- **Constat** : `if (null !== \Fiber::getCurrent())` répond « oui » depuis n'importe quel fiber, pas seulement celui que `WorkflowFiberDriver` pilote. Si du code s'exécute dans un fiber imbriqué — un client HTTP à fibers appelé depuis une activité drainée en ligne, `revolt/event-loop` chargé par une dépendance — le `Fiber::suspend()` rend la main au pilote du fiber *intérieur*, et la ligne 71 lit `getResult()` sur un awaitable non réglé : `RuntimeException('Awaitable is not settled')` très loin de sa cause.
- **Amont** : `Revolt\EventLoop\Internal\DriverSuspension::suspend()` compare l'identité du fiber créateur et refuse autrement — `if ($fiber !== \Fiber::getCurrent()) { throw new \Error('Must not call suspend() from another fiber'); }` (https://github.com/revoltphp/event-loop/blob/main/src/EventLoop/Internal/DriverSuspension.php).
- **Correctif** : faire porter au runtime (ou au contexte) une `\WeakReference` sur le fiber ouvert par `WorkflowFiberDriver::run()` et comparer par identité, avec un message explicite quand un fiber étranger appelle `await()`.
