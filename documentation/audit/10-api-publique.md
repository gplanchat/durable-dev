# Conception d'API publique — immutabilité, nommage, objets valeur, invariants

## Synthèse

Sur l'immutabilité, le périmètre est irréprochable et se mesure : aucun setter, aucun `with*()`, aucune
propriété publique mutable dans les huit dossiers, **aucune classe non `final`**, les 29 événements sont
`final readonly`, et les awaitables ne sont mutables que là où la sémantique de promesse l'exige. Mais
la seconde moitié de la question — « avec leurs invariants validés à la construction » — reçoit une
réponse inverse : un seul `throw` de validation existe sur une soixantaine de types constructibles. Le
même écart se rejoue sur les objets valeur : `ExecutionId` existe, est correct, et n'apparaît en
type-hint nulle part face à 152 signatures `string $executionId` — et sur `WorkflowHistorySourceInterface`
une tâche de migration cochée `[x]` en août décrit un retour `Duration` que le code ne rend pas. La
hiérarchie d'exceptions est plate : 16 classes, aucune interface marqueur, donc aucun `catch` par paquet.
Le nommage est cohérent à deux contre un, et rien du périmètre n'est marqué `@internal`.

## Constats

### C1 — Les invariants ne sont pas validés à la construction : un seul `throw` sur ~60 types

- **Fichier** : `src/Durable/Awaitable/QuorumAwaitable.php:37` (le seul), `src/Durable/Attribute/AsActivity.php:10`,
  `src/Durable/Event/ExecutionStarted.php:11`, `src/Durable/Failure/FailureEnvelope.php:14`
- **Gravité** : majeur
- **Constat** : `grep -rn 'throw new \\?Invalid|InvalidArgument|LogicException|Assert::'` sur les huit
  dossiers ne retourne **qu'une** occurrence, `QuorumAwaitable`, dont le constructeur refuse un quorum
  inatteignable avec un message qui nomme la panne évitée. Partout ailleurs les constructeurs acceptent
  tout : `new AsActivity('')`, `new ExecutionStarted('')`, `new FailureEnvelope('', '')`,
  `new AsSignalMethod('')` construisent sans broncher. Le `readonly` gèle donc un état qui n'a jamais
  été vérifié — l'objet est immuable, pas valide, et l'erreur ressort ailleurs, plus tard.
- **Amont** : `webmozart/assert`, README — « efficient assertions to test the input and output of your
  methods », avec l'exemple canonique d'un `Assert::greaterThan()` **dans le constructeur**
  (https://github.com/webmozarts/assert#readme) ; c'est exactement la forme de `QuorumAwaitable:37`.
- **Correctif** : garder au moins l'identifiant et le nom non vides sur les types de frontière
  (attributs, événements, `FailureEnvelope`, `ExecutionId`) — `QuorumAwaitable` prouve que le projet sait
  écrire le garde, il n'est simplement écrit qu'une fois.

### C2 — `ExecutionId` est un objet valeur mort : les ports transportent des `string`

- **Fichier** : `src/Durable/Port/WorkflowResumeDispatcher.php:18`, `src/Durable/Event/Event.php:9`,
  `src/Durable/ExecutionId.php:18`
- **Gravité** : majeur
- **Constat** : comptage sur `src/Durable` — `152` occurrences de `string $executionId`, `0` occurrence
  de `ExecutionId` en type de paramètre ou de retour, et un seul appel dans tout `src/`
  (`Handler/ResumeWorkflowHandler.php:90`), immédiatement déballé par `->toString()`. Le VO ne protège
  donc rien : `dispatchResume($workflowType)` compile, et `ExecutionId::fromString('')` est accepté.
  Aucun change en cours dans `openspec/changes/` ne retype cet identifiant — ce n'est pas une migration
  à mi-parcours.
- **Amont** : l'ADR du projet, `documentation/adr/DUR031-value-objects-across-ports-and-wire-ownership.md`
  (« Value objects cross the ports … Invariants did not travel ») ; côté amont,
  `Symfony\Component\Uid\Uuid::fromString()` lève `InvalidArgumentException` sur une valeur non conforme
  (https://github.com/symfony/symfony/blob/7.3/src/Symfony/Component/Uid/Uuid.php).
- **Correctif** : soit valider le format dans `fromString()` et retyper les ports
  (`WorkflowResumeDispatcher`, `WorkflowTimerDispatcher`, `WorkflowLifecycleInterface`,
  `Event::executionId()`), soit supprimer `ExecutionId` — un VO qu'aucune signature n'exige coûte de la
  lecture sans rendre de garantie.

### C3 — `WorkflowHistorySourceInterface` : la migration vers les VO est cochée, le code n'a pas bougé

- **Fichier** : `src/Durable/Port/WorkflowHistorySourceInterface.php:10` (la promesse), `:23`, `:58`,
  `:74`, `:91`, `:128` (les retours réels)
- **Gravité** : majeur
- **Constat** : la docstring de classe annonce « Recorded timings are returned as `Duration` …
  Third-party implementations written against the previous `float` return must adapt. See ADR DUR031 »,
  et `openspec/changes/archive/2026-08-26-value-objects-through-ports/tasks.md:11` coche `[x] 2.4
  WorkflowHistorySourceInterface::findTimerSlotResult() returns a Duration`. Le code rend toujours
  `array{id: string, scheduledAt: float, failed: \Throwable|null}`, plus quatre autres tableaux de forme
  (`{result, failed}` ×2, `{childExecutionId, result, failed}`, `{position, kind, name, payload}`). Le
  côté écriture du même port documente pourtant sa propre migration réussie
  (`WorkflowCommandBufferInterface:27-29`) : seule la moitié lecture est restée en arrière, avec une
  docstring qui affirme le contraire.
- **Amont** : ADR DUR031, section « Value objects cross the ports », qui nomme explicitement les **deux**
  interfaces et annonce le BC break aux implémenteurs tiers ; change archivé du 2026-08-26, tâche 2.4.
- **Correctif** : introduire des **DTO de résultat côté lecture** (`TimerSlotResult`,
  `ActivitySlotResult`, `RecordedMessage`) — l'alternative que l'ADR rejette est « a command DTO per port
  method », côté écriture, ce qui ne couvre pas ce cas ; à défaut, décocher la tâche et corriger la
  docstring, qui aujourd'hui ment sur le contrat.

### C4 — Aucune interface marqueur d'exception : on ne peut pas attraper « une erreur Durable »

- **Fichier** : `src/Durable/Exception/DurableActivityFailedException.php:11` (et les 15 autres)
- **Gravité** : majeur
- **Constat** : les 16 exceptions du paquet étendent directement `\RuntimeException` (14) ou `\Exception`
  (2) ; aucune `Gplanchat\Durable\Exception\ExceptionInterface` n'existe. La seule interface d'exception
  du projet, `Port\DeclaredActivityFailureInterface extends \Throwable`, sert un autre rôle — elle décrit
  une panne **métier** qu'une activité déclare pour la rejouer, pas les pannes du moteur. Un intégrateur
  doit donc énumérer 16 classes pour distinguer une panne Durable d'un `\RuntimeException` de son propre
  code, et l'énumération se périme au premier ajout.
- **Amont** : convention de composant Symfony — chaque composant publie une interface marqueur vide
  étendant `\Throwable`, p. ex. `Messenger/Exception/ExceptionInterface.php`
  (https://github.com/symfony/symfony/blob/7.3/src/Symfony/Component/Messenger/Exception/ExceptionInterface.php).
- **Correctif** : ajouter `Exception\ExceptionInterface extends \Throwable` et la faire implémenter par
  les 16 classes (non cassant : on n'ajoute qu'un parent), avec une seconde interface pour séparer les
  exceptions de flux (`ContinueAsNewRequested`, `ChildWorkflowStartDeferred`) des vraies pannes.

### C5 — `FailureEnvelope` duck-type `toHistoryPayload()` alors qu'une interface existe deux lignes plus haut

- **Fichier** : `src/Durable/Failure/FailureEnvelope.php:38`
- **Gravité** : majeur
- **Constat** : `fromThrowable()` teste d'abord `$e instanceof DeclaredActivityFailureInterface`, puis
  retombe sur `method_exists($e, 'toHistoryPayload')`. Ce second point d'extension n'a **aucun
  implémenteur** dans le dépôt (`grep -rn toHistoryPayload src/` → 2 occurrences, toutes deux dans ce
  fichier), aucune interface, aucune documentation. C'est un contrat public invisible : personne ne peut
  le découvrir, et personne ne peut le retirer sans risquer de casser un tiers qui l'aurait deviné.
- **Amont** : promesse de rétrocompatibilité Symfony — ce qui n'est ni publié comme interface ni marqué
  `@internal` est réputé public et figé (https://symfony.com/doc/current/contributing/code/bc.html) ; la
  voie normale est une interface, ce que le projet fait déjà avec `DeclaredActivityFailureInterface`.
- **Correctif** : supprimer la branche `method_exists` (aucun appelant), ou la promouvoir en
  `HistoryPayloadAwareInterface` documentée à côté de `DeclaredActivityFailureInterface`.

### C6 — Nommage : suffixe `Interface` appliqué à 2 contre 1, deux exceptions homonymes, un `@template` inerte

- **Fichier** : `src/Durable/Port/WorkflowResumeDispatcher.php:12`,
  `src/Durable/Port/WorkflowTimerDispatcher.php:22`,
  `src/Durable/Exception/WorkflowCancelledException.php:13`,
  `src/Durable/Exception/WorkflowCancelledFailure.php:19`, `src/Durable/Awaitable/Awaitable.php:21`
- **Gravité** : mineur
- **Constat** : la prémisse de la question posée est fausse — `WorkflowResumeDispatcher` et
  `WorkflowTimerDispatcher` **sont** des interfaces (`interface` en ligne 12 et 22), dont les
  implémentations no-op `NullWorkflowResumeDispatcher` / `NullWorkflowTimerDispatcher` cohabitent dans le
  même dossier ; le seul écart est le suffixe, appliqué à 18 interfaces du cœur contre 9 sans, dont ces
  deux-là sur les 10 de `Port/`. Deux autres frottements de nommage : `WorkflowCancelledException` et
  `WorkflowCancelledFailure` ne diffèrent que par le suffixe, portent les deux mêmes propriétés
  (`$executionId`, `$reason`) et désignent des choses opposées — terminaison côté moteur contre signal
  levé **dans le fiber** au point d'attente ; et `Awaitable` déclare `@template TValue` sans que
  `getResult(): mixed` porte `@return TValue`, ce qui rend le générique inerte chez l'appelant.
- **Amont** : standards de code Symfony, « Suffix interfaces with `Interface` »
  (https://symfony.com/doc/current/contributing/code/standards.html). Le suffixe `Failure` des exceptions
  Temporal (`WorkflowCancelledFailure`, « Équivalent du `CanceledFailure` Temporal ») est un choix
  d'interopérabilité assumé, pas un écart — ce n'est pas lui qui pose problème, c'est la collision.
- **Correctif** : renommer `WorkflowCancelledFailure` en `WorkflowCancellationRequestedFailure` (ce que
  dit déjà son propre message), aligner les deux ports en `*Interface`, annoter `@return TValue` — les
  deux renommages passant par une règle Rector, selon la règle projet « tout BC a sa procédure de
  migration ».

### C7 — `UuidGeneratorInterface` promet un identifiant monotone que l'implémentation ne produit pas

- **Fichier** : `src/Durable/Uuid/UuidGeneratorInterface.php:16`,
  `src/Durable/Uuid/NativeUuidV7Generator.php:19-28`
- **Gravité** : mineur
- **Constat** : la docstring du port dit « UUID v7 or equivalent **monotonic** UUID ».
  `NativeUuidV7Generator` remplit `rand_a` (12 bits) avec `random_bytes()` pur, sans compteur ni
  sous-milliseconde : deux identifiants générés dans la même milliseconde s'ordonnent au hasard. Le
  contrat du port promet donc une garantie que sa seule implémentation ne tient pas.
- **Amont** : `Symfony\Component\Uid\UuidV7` incrémente `rand_a` d'un delta 24 bits dérivé d'un hachage
  quand l'horodatage n'a pas changé — « Within the same ms, we increment the rand part by a random
  24-bit number » (https://github.com/symfony/symfony/blob/7.3/src/Symfony/Component/Uid/UuidV7.php) ;
  c'est cette mécanique supplémentaire qui rend un v7 monotone, elle est absente ici.
- **Correctif** : soit implémenter le compteur monotone sur `rand_a`, soit retirer le mot « monotonic »
  de la docstring du port — un contrat qu'on ne tient pas est pire qu'un contrat absent.

### C8 — Zéro `@internal` sur tout le périmètre : la surface publique est plus large que voulu

- **Fichier** : `src/Durable/Awaitable/AwaitableInspector.php:10`,
  `src/Durable/Awaitable/AwaitableCancellation.php:18`,
  `src/Durable/Failure/ActivityFailureEventFactory.php:15`
- **Gravité** : mineur
- **Constat** : `grep -rl "@internal"` ne retourne **aucun** fichier dans les huit dossiers audités, et 6
  seulement dans tout `src/Durable` (207 fichiers). Or `AwaitableInspector` se décrit lui-même comme
  « prédicats structurels … partagés par les points qui décident du réveil » — un détail
  d'implémentation du moteur —, et `ActivityFailureEventFactory` fabrique des événements de journal que
  personne hors du moteur n'a de raison de construire. Sans marquage, ces classes tombent sous la
  garantie de rétrocompatibilité au même titre que `WorkflowEnvironment`.
- **Amont** : promesse de rétrocompatibilité Symfony — « code marked with the `@internal` tags are
  excluded from our Backward Compatibility promise »
  (https://symfony.com/doc/current/contributing/code/bc.html).
- **Correctif** : marquer `@internal` les utilitaires moteur (`AwaitableInspector`,
  `AwaitableCancellation`, `ActivityFailureEventFactory`) **avant** le premier tag stable — après, c'est
  un BC break qui demande sa propre procédure de migration.

## Points sains, dits en une ligne chacun

- **Mutabilité** : aucun setter ni `with*()` dans les huit dossiers, et aucune propriété publique
  mutable — la question « readonly ou setters ? » est tranchée du bon côté.
- **`final`** : **aucune classe non `final`** dans le périmètre ; la moitié « quelles classes devraient
  être `final` ? » de la question est déjà close, seule la moitié `@internal` (C8) reste ouverte.
- **Attributs** : les 12 sont `final` et immuables, à une hésitation de style près entre `final class` +
  `public readonly $x` (7) et `final readonly class` + `public $x` (5), sans conséquence.
- **`array` dans les API** : le `array $payload` des activités et des signaux est une exception
  **explicitement décidée** par l'ADR DUR031 (« It is caller data, genuinely untyped ») — ce n'est pas un
  écart, et C3 ne vise que les enveloppes moteur.
