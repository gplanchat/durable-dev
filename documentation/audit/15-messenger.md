# Messenger et Serializer — transports, middleware, stamps, retry

## Synthèse
Au regard des conventions Symfony Messenger, deux axes du périmètre sont sains et méritent d'être dits en une ligne : les DTO de `src/Durable/Transport/` sont des `final readonly` de scalaires, tableaux, enums et value objects sans ressource ni closure, donc sérialisables par le `PhpSerializer` par défaut sans serializer dédié non documenté (le `\BackedEnum` du nom de signal est aplati dès le constructeur, `DeliverWorkflowSignalMessage.php:25`) ; et aucun transport ne tente de poser `SentStamp` lui-même, laissant `SendMessageMiddleware` faire son travail, tandis que `DispatchAfterCurrentBusStamp` et `DelayStamp` sont employés à bon escient.
La position de la pile est elle aussi correcte : `RegisterDurableMiddlewarePass` documente justement l'absence de balise `messenger.middleware` et insère en index 0 (ou 1 derrière `traceable`, garde fondée puisque `FrameworkExtension.php:2556` fait bien l'`array_unshift`), donc au-dessus de `dispatch_after_current_bus`, du `doctrine_transaction` de l'utilisateur, de `send_message` et de `handle_message` — exactement là où un verrou doit être ; le défaut est ailleurs (C3).
Les messages d'erreur de `TemporalTransportFactory` sont, eux, exploitables : ils nomment le purpose attendu, l'option manquante et la clé de configuration à activer ; seule réserve mineure, ce sont des `\LogicException`/`\InvalidArgumentException` globales là où `vendor/symfony/messenger/Transport/TransportFactory.php:14,57-60` lève celles de `Symfony\Component\Messenger\Exception`.
En revanche les transports de `src/Bridge/Temporal/Messenger/` n'implémentent `TransportInterface` que de nom : trois d'entre eux font le travail *dans* `get()` et retournent `[]`, si bien qu'aucune enveloppe n'existe jamais — ni `TransportMessageIdStamp`, ni `ack()`/`reject()` réels, ni stratégie de retry, ni `failure_transport`, ni `--limit`.
Le retry des activités est entièrement réimplémenté à côté de Messenger, et ni `RecoverableExceptionInterface` ni `UnrecoverableMessageHandlingException` n'apparaissent nulle part dans `src/`.

## Constats

### C1 — Les transports Temporal receive-only font le travail dans `get()` et ne rendent aucune enveloppe
- **Fichier** : `src/Bridge/Temporal/Messenger/TemporalJournalTransport.php:29-34`, `src/Bridge/Temporal/Messenger/TemporalActivityWorkerTransport.php:21-26`, `src/Bridge/Temporal/Messenger/TemporalNexusWorkerTransport.php:21-26`
- **Gravité** : majeur
- **Constat** : `get()` appelle `processOne()`/`pollOnce()` — qui exécutent la tâche, écrivent le journal et répondent à Temporal — puis `return []` ; `ack()` et `reject()` ont un corps vide. Le `Worker` ne voit donc jamais d'enveloppe : `retry_strategy` et le `failure_transport: failed` déclaré en `symfony/config/packages/messenger.yaml:10` sont de la configuration morte pour ces transports. `messenger:consume --limit=N` ne s'arrête jamais non plus, `Worker.php:147` n'émettant que `WorkerRunningEvent($this, true)` quand `get()` n'a rien rendu.
- **Amont** : `vendor/symfony/messenger/Transport/Receiver/ReceiverInterface.php:22-46` (« If applicable, the Envelope should contain a TransportMessageIdStamp ») ; `vendor/symfony/messenger/Worker.php:115,147` ; `vendor/symfony/messenger/EventListener/StopWorkerOnMessageLimitListener.php:38` (`if (!$event->isWorkerIdle() && ++$this->receivedMessages …)`) ; https://symfony.com/doc/current/messenger/custom-transport.html (l'exemple de référence retourne `[]` *sans avoir rien exécuté* et pose `TransportMessageIdStamp`).
- **Correctif** : soit rendre une enveloppe portant la task token en `TransportMessageIdStamp` et déplacer l'exécution dans un handler, `ack()`/`reject()` devenant `RespondWorkflowTaskCompleted`/`Failed` ; soit documenter noir sur blanc que ces trois transports détournent la boucle `messenger:consume` et que `retry_strategy`, `failure_transport` et `--limit` y sont sans effet.

### C2 — `get()` laisse remonter les erreurs gRPC brutes, hors du contrat `TransportException`
- **Fichier** : `src/Bridge/Temporal/Messenger/TemporalJournalTransport.php:31`, `src/Bridge/Temporal/Worker/WorkflowTaskProcessor.php:44-49`, `src/Bridge/Temporal/Worker/TemporalActivityWorker.php:52-59`
- **Gravité** : majeur
- **Constat** : le RPC de poll n'est entouré d'aucun `try`/`catch` et `Worker::run()` n'en pose pas non plus autour de `$receiver->get()`. Une coupure du frontend Temporal (`UNAVAILABLE`, `DEADLINE_EXCEEDED`) fait donc sortir l'exception de la boucle et tuer `messenger:consume`, sans passer par les listeners d'arrêt propre ni par le journal d'erreurs Messenger.
- **Amont** : `vendor/symfony/messenger/Transport/Receiver/ReceiverInterface.php:44` (`@throws TransportException If there is an issue communicating with the transport`) ; `vendor/symfony/messenger/Worker.php:111-116` (appel non protégé).
- **Correctif** : envelopper le poll des trois `get()` en `catch (\Throwable $e) { throw new TransportException($e->getMessage(), 0, $e); }`, et traiter `DEADLINE_EXCEEDED` — fin normale d'un long-poll — comme un `return []` silencieux.

### C3 — Le verrou de reprise est pris aussi du côté producteur, faute de garde `ReceivedStamp`
- **Fichier** : `src/Bridge/Dbal/Messenger/SingleResumeLockMiddleware.php:41-42`, enregistré par `src/DurableBundle/DependencyInjection/DurableExtension.php:161-166` et inséré en tête par `src/DurableBundle/DependencyInjection/Compiler/RegisterDurableMiddlewarePass.php:50-54`
- **Gravité** : majeur
- **Constat** : placé en tête de pile, le middleware est traversé au **dispatch** comme à la consommation. Une requête HTTP qui envoie un `ResumeWorkflowMessage` — routé en asynchrone vers `durable_workflows` (`symfony/config/packages/messenger.yaml:18`) — exécute `$lock->acquire(true)`, acquisition bloquante de TTL 300 s, avant même que le message ne parte vers le transport : elle attend qu'un consumer ait fini la passe en cours pour cette exécution.
- **Amont** : `vendor/symfony/messenger/Middleware/DeduplicateMiddleware.php:31` — le middleware à verrou upstream distingue les deux sens par `if (!$envelope->last(ReceivedStamp::class))` ; même garde dans `vendor/symfony/messenger/Middleware/RejectRedeliveredMessageMiddleware.php`.
- **Correctif** : n'acquérir le verrou que si `$envelope->last(ReceivedStamp::class)` est présent, et laisser le dispatch passer sans attente.

### C4 — `MessengerActivityTransport` acquitte avant traitement et retient une enveloppe sans jamais la rendre
- **Fichier** : `src/DurableBundle/Transport/MessengerActivityTransport.php:59-73` et `:84-100`
- **Gravité** : majeur
- **Constat** : `dequeue()` appelle `$this->receiver->ack($envelope)` (ligne 67) *avant* de retourner le message à l'appelant, qui l'exécute ensuite — sémantique at-most-once, un crash pendant l'activité perd la tentative. Pire, `isEmpty()` (ligne 90) consomme une enveloppe et la stocke dans `$this->pending` sans ack ni requeue : si le processus s'arrête là, un receiver Doctrine laisse le message invisible jusqu'à `redeliver_timeout`. Le chemin n'est atteint que si `ExecutionRuntime` tourne en mode non distribué (`src/Durable/ExecutionRuntime.php:80,139,200`), mais rien dans le port ne l'interdit et le service est déclaré public (`src/DurableBundle/DependencyInjection/DurableExtension.php:452-459`).
- **Amont** : `vendor/symfony/messenger/Worker.php:167-199` — l'ack upstream est différé après le retour du bus et porte l'exception éventuelle ; `vendor/symfony/messenger/Transport/Receiver/ReceiverInterface.php:48-52` (« Acknowledges that the passed message **was handled** »).
- **Correctif** : ne pas acquitter dans `dequeue()` — exposer `ack`/`reject` au niveau du port, ou faire de `isEmpty()` une lecture non destructive et refuser ce transport hors mode distribué dès la compilation du conteneur.

### C5 — Retry des activités entièrement réimplémenté à côté de Messenger
- **Fichier** : `src/Durable/Worker/ActivityMessageProcessor.php:138-195`, `src/DurableBundle/Handler/ActivityRunHandler.php:19-22`
- **Gravité** : majeur
- **Constat** : `process()` attrape tout `\Throwable` et ne le relance jamais ; il ré-enfile lui-même le message via `$this->activityTransport->enqueue($message->retryingIn(...))` (ligne 190), traduit en `DelayStamp` par `MessengerActivityTransport.php:33-40`. Le handler rend donc toujours un succès à Messenger : le `failure_transport` de `durable_activities` n'est jamais alimenté et `messenger:failed:show` reste vide, alors que les tentatives sont illimitées par défaut (`RetryLimit::unlimited()`, et `RetryLimit::ofRetries(0)` retourne `unlimited()` — `src/Durable/Activity/RetryLimit.php:64-67`). Aucune occurrence de `RecoverableExceptionInterface` ni de `UnrecoverableMessageHandlingException` dans `src/`.
- **Amont** : `vendor/symfony/messenger/Retry/RetryStrategyInterface.php` et `vendor/symfony/messenger/Exception/UnrecoverableMessageHandlingException.php` — le contrat Messenger veut qu'un handler *relaie* l'échec, le worker décidant du retry ou du renvoi vers le `failure_transport`.
- **Correctif** : la sémantique Temporal (retry par activité, `nonRetryableExceptions`, backoff) justifie de ne pas déléguer, mais alors relancer l'échec définitif en `UnrecoverableMessageHandlingException` pour que le message atterrisse tout de même dans le `failure_transport`, et documenter que `retry_strategy` est sans effet sur `durable_activities`.

### C6 — `TemporalTransportFactory` écrase le DSN pour `purpose=journal` et ignore le serializer reçu
- **Fichier** : `src/Bridge/Temporal/Messenger/TemporalTransportFactory.php:48` et `:42,65`
- **Gravité** : majeur
- **Constat** : `$resolved = $this->temporalConnection ?? $connection;` — dès que le bundle a injecté une connexion, le DSN écrit dans `framework.messenger.transports` est parsé puis jeté en silence ; deux transports visant deux namespaces Temporal différents finissent sur la même connexion sans un mot. Le `SerializerInterface` reçu n'est par ailleurs transmis qu'au transport interne du purpose `application` (ligne 65) et n'est utilisé par aucun des trois autres, ce qui rend inopérante toute clé `serializer:` posée sur ces transports.
- **Amont** : `vendor/symfony/messenger/Transport/InMemory/InMemoryTransportFactory.php:37-42` — la fabrique construit depuis le `$dsn` reçu et relaie `$serializer` ; même contrat dans https://symfony.com/doc/current/messenger/custom-transport.html.
- **Correctif** : n'appliquer `$this->temporalConnection` que si le DSN ne porte pas d'hôte explicite, ou lever une erreur claire quand les deux divergent ; et documenter que `serializer:` est sans effet sur les purposes receive-only.

### C7 — `DeliverWorkflowSignalHandler` n'a aucune garde d'idempotence
- **Fichier** : `src/DurableBundle/Handler/DeliverWorkflowSignalHandler.php:22-30`
- **Gravité** : mineur
- **Constat** : le handler appelle `$this->eventStore->append(new WorkflowSignalReceived(...))` sans condition, et `EventStoreInterface::append()` (`src/Durable/Store/EventStoreInterface.php:16`) n'opère aucune déduplication. Une redélivrance Messenger — inévitable en at-least-once dès que `DeliverWorkflowSignalMessage` est routé vers un transport asynchrone plutôt que le `sync` de `symfony/config/packages/messenger.yaml:21` — inscrit donc le signal deux fois dans le journal. Le chemin activité, lui, se protège par `ActivityEventJournal::hasTerminalOutcomeForActivity()` (`ActivityMessageProcessor.php:45-51`) ; le chemin signal n'a pas d'équivalent.
- **Amont** : `vendor/symfony/messenger/Transport/Receiver/ReceiverInterface.php:22-46` et `vendor/symfony/messenger/Middleware/RejectRedeliveredMessageMiddleware.php` — la redélivrance est une propriété assumée du modèle, à laquelle le handler doit être idempotent.
- **Correctif** : porter un identifiant de livraison dans `DeliverWorkflowSignalMessage` et vérifier son absence dans le flux avant l'`append`, sur le modèle du garde-fou déjà en place côté activités.

### C8 — `TemporalApplicationTransport` décore un transport et perd ses interfaces optionnelles
- **Fichier** : `src/Bridge/Temporal/Messenger/TemporalApplicationTransport.php:17-22`
- **Gravité** : mineur
- **Constat** : le décorateur n'implémente que `TransportInterface`, si bien que le transport interne (Doctrine, AMQP, Redis…) perd `SetupableTransportInterface`, `MessageCountAwareInterface`, `ListableReceiverInterface`, `KeepaliveReceiverInterface` et `CloseableTransportInterface` : `messenger:setup-transports` répond « does not support setup » et la table `messenger_messages` n'est jamais créée, `messenger:stats` affiche « n/a », `messenger:failed:show`/`:retry` sont inopérants. Gravité contenue par le fait qu'aucune configuration du dépôt n'instancie le purpose `application`.
- **Amont** : `vendor/symfony/messenger/Command/SetupTransportsCommand.php:70-71` (`if (!$transport instanceof SetupableTransportInterface)`).
- **Correctif** : implémenter les interfaces optionnelles avec délégation conditionnelle (`$this->inner instanceof SetupableTransportInterface ? $this->inner->setup() : null`).
