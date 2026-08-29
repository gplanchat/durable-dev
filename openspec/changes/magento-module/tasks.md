# Tasks

## 1. Build the instrument, and probe what the design guessed

A Tier 1 bootstrap has no unit test that proves it boots. The bench **is** the test harness, so it
comes first — and while it is being built it answers the four questions `design.md` records as
unmeasured.

- [x] 1.1 Track the `magento/` overlay the way `sylius/` is tracked. **The task was wrong about
      what that means.** "Sources yes, `vendor/` no" is not enough: with `vendor/` already excluded
      by the root `.gitignore`, `git add -An magento` still stages **10 178 files** — `dev/` alone
      brings 7 256 — all of them written by composer. `sylius/` is 220 files because a Sylius
      skeleton *is* project code; the Magento equivalent is eight files of overlay.
      So `magento/.gitignore` inverts the rule: ignore everything, re-allow the overlay by name.
      A file composer adds tomorrow stays out without anyone thinking about it, which an exclusion
      list cannot do. Verified: 8 files tracked, and three simulated distribution files change
      nothing. OST004's row corrected.
- [x] 1.2 `composer install` reaches a working `bin/magento`. **It already did, and the task's
      evidence was wrong.** "No `vendor/magento/framework`" looked at the wrong path: Mage-OS ships
      under `vendor/mage-os/`, where 363 packages sit. `bin/magento --version` answers *Mage-OS CLI
      2.2.0 (based on Magento 2.4.8-p4)*, and `setup:db:status` answers *All modules are up to
      date* — the application is installed, not merely downloaded.
      What was actually broken is the bench's **ports**. Its defaults collide with the benches
      beside it: MySQL on 3306 is held by `sylius-mysql-1`, and Temporal on 7233 by the
      `temporal server start-dev` the integration suite runs. Magento was therefore talking to
      Sylius's database server and being refused. Defaults moved to 33306 and 7234, the stack
      comes up whole, and `.env.example` says why.
      `check-php-extensions.sh` was a claim; it is now a measurement: all eighteen present on
      PHP 8.2.33, exit 0.
- [x] 1.3 **What a dying consumer leaves behind: silence** — measured, `magento/probe-queue.php`
      plus a bench-local probe module — it stays out of the published package. The row stays `IN_PROGRESS` with `number_of_trials = 0`, a
      `queue_lock` row survives the dead process, no dead letter, nothing logged, and a fresh
      consumer waits 25 s beside it without taking it. Recovery needs **two** cron jobs —
      `mysqlmq_clean_messages` (twice a day, after a **24 h** `retry_inprogress_after`) and
      `messagequeue_clean_outdated_locks` (hourly) — and **their order decides whether the message
      runs or is swallowed**: a retry that lands while the lock stands makes `Consumer` acknowledge
      it *without dispatching*, `COMPLETE`, handler never called. Redelivery restarts the handler
      **from the beginning**. The shipped defaults are saved by their own sloth; a shop that
      shortens the retry delay is not. §4.3 is where this gets pinned.
- [x] 1.4 **Whether `LockManagerInterface` is shared across processes. It is** — measured, two
      processes, `magento/probe-lock.php`. The bench configures `lock.provider: db` explicitly;
      `Magento\Framework\Lock\Backend\Database` sits behind a `Lock\Proxy`, and a second process
      is refused a lock the first holds. **A killed holder releases it** — `GET_LOCK` dies with its
      connection, so a crashed consumer does not wedge an execution.
      **But the backend answers `true` without locking anything when `isDbAvailable()` is false**
      (read, not measured), which is the shape the startup refusal of §2.3 has to catch: a lock that
      always says yes is worse than none.
- [x] 1.5 **A long-poll transport does not starve the consumer — it duplicates the message.**
      Measured. A handler held a message 200 s and finished normally: the runner sets no deadline,
      and the bench's MySQL `wait_timeout` is 28800 s. But the retry timer looks only at
      `updated_at`, never at whether the first consumer is done: with the delay shortened to a
      minute, **two live processes ran the same message at once** (pids 442111 and 445235). A
      worker holds its message by construction and outlives any delay, so **the worker cannot be a
      queue message** — §5.1's `bin/magento` commands stop being a preference. And Magento's queue
      offers no mutual exclusion at all, which makes §1.4's lock the only thing between two
      consumers and a forked journal.

## 2. The module boots

- [x] 2.1 `src/DurableModule` with `registration.php` declaring `Gplanchat_Durable`, `etc/module.xml`,
      and a `composer.json` naming `gplanchat/durable-magento`. `bin/magento module:status` lists it.
- [x] 2.2 The bench's path repository resolves. **Two host constraints found on the way**, both
      recorded in `design.md`: Mage-OS's `composer-dependency-version-audit-plugin` refuses a path
      package that also exists on Packagist, and Magento's generated `Interceptor` cannot extend a
      `final` class — which is the house style everywhere else in this repository.
- [x] 2.3 **Composer refuses the SQL bridges; no code does.** `gplanchat/durable-magento` declares
      `conflict` on `gplanchat/durable-bridge-dbal` and `gplanchat/durable-bridge-illuminate`.
      Measured on the bench: `composer require gplanchat/durable-bridge-dbal` ends in *"Conclusion:
      remove gplanchat/durable-magento (conflict analysis result)"* and writes nothing. The
      incoherent installation never exists, so no process boots into it.
      **Author's decision on PR #172**, replacing a first version that had built the refusal in
      code — a constraint the package manager can express does not belong in a runtime that only
      learns of it after the wrong thing is installed. Consequence: the module has **no backend
      configuration surface**, so there is nothing to mistype; §5 is where a second backend, and
      therefore a choice, starts to exist. What `conflict` cannot carry is the *reason* — that stays
      in `ALLOWED.magento`, the selector, and `design.md`.

## 3. Workflows and activities are discoverable

- [x] 3.1 **`di.xml` carries two arrays; the contract is not one of them.** Workflow classes are
      named, activity handlers are placed, and the factory reads each handler's interfaces and keeps
      the ones carrying `#[ActivityMethod]` — one declaration fewer to get wrong, and the names stay
      the attributes'. It reuses the same two core objects the bundle's compiler pass does;
      `PayloadToContractMethodInvoker` moved from `durable-bundle` to `durable` for it, since two
      hosts now need it (**BREAKING CHANGE**) — and it ships its migration procedure, as the rule
      requires: a cumulative `durable-upgrade.php` Rector set, an `UPGRADE.md` at the repository
      root, and the one thing Rector cannot do written out (a compiled Symfony container keeps the
      fully-qualified name and wants its `cache:clear`).
      **The refusal is the mechanism**: `MagentoRuntime::run()` used to self-register an unknown
      workflow, which made declaration meaningless and left `Scenario: An undeclared workflow fails
      at the moment of the mistake` false since 3.2. It now throws naming the class and the `di.xml`
      argument — the scenario is discharged at the bench, and the demo command's three hand-written
      closures are gone.
- [x] 3.2 A workflow class written once runs unmodified on the in-memory backend inside the bench.
      `bin/magento durable:demo ORD-4242` runs charge → reserve → notify in order and exits 0.
      `PlaceOrderWorkflow` imports nothing from Magento — no `ObjectManager`, no
      `ResourceConnection` — which is the whole point: everything under the ports is
      `gplanchat/durable` unchanged.

## 4. ~~The queue carries the work~~ — abandoned, and here is what measured it

**Author's decision, 28/08.** Nothing of Durable rides Magento's `MessageQueue`, because on the only
durable backend this host reaches there is nothing for it to carry.

`TemporalWorkflowCommandBuffer` schedules an activity as a
`ScheduleActivityTaskCommandAttributes` — a Temporal command on a Temporal task queue.
`EventStoreCommandBuffer`, which puts an `ActivityMessage` on the host's queue, is the
**non-Temporal** path. Magento has no native journal and will not get one (`memory` and `temporal`,
decided). So with Temporal the host's queue carries neither activities nor resumes; and with
`memory` everything is one process, where a queue serves nothing that survives it.

Task 4 and task 5 were never a sequence — they were alternatives, and only one of them is reachable
here. §5.3 had already measured the consequence without naming it: the execution stuck because its
activity had been dispatched in-process, and the answer is a Temporal activity worker, not a Magento
topic.

- [x] ~~4.1 the four XML files~~ — the `request` type was measured first and the finding stands on
      its own (`design.md`): Magento's encoder **empties** a transport object without throwing, and
      `string[]` drops associative keys. Both are recorded; the XML is not written.
- [x] ~~4.2 the five roles as handlers~~ — the resume orchestration went to `gplanchat/durable`
      instead, behind `WorkflowTimerDispatcher`, where six non-Symfony hosts share it.
- [x] ~~4.3 one resume at a time~~ — §1.4 measured `LockManagerInterface` shared across processes
      and that stands; what needed the lock was two consumers on one queue, and there is no queue.
      ⚠ If a host-native journal is ever added, this entry comes back with it.
      **Et le delta de spec a suivi, le 28/08.** L'exigence *One execution is replayed by one
      consumer at a time* et ses deux scénarios — dont *A process-local lock is refused before it
      can cost anything*, que rien n'implémentait — sont retirés de
      `specs/magento-host/spec.md`&nbsp;: un change ne s'archive pas sur une promesse qu'il a
      décidé de ne pas tenir, et une exigence sans propriétaire aurait bloqué l'archivage sans dire
      pourquoi. Le motif reste écrit à trois endroits plutôt que d'être effacé — l'exigence voisine
      dit en deux phrases pourquoi aucune règle de verrou ne la suit, le `proposal.md` barre sa
      puce en la datant, et le `design.md` relit son propre raisonnement d'un cran plus loin :
      chacune de ses phrases partait de « deux consommateurs dépilent deux reprises », et cette
      prémisse est partie avec la file.

## 3bis. What the published package must not carry

- [x] 3bis.1 **The demo left the package.** `PlaceOrderWorkflow`, `OrderActivities`,
      `DemoOrderActivities` and `durable:demo` moved to the bench module; the published `di.xml`
      declares **no workflow at all**, its two arrays empty with a commented example of where a
      project adds its own. An integration module has no business making every consumer carry
      workflows that are not theirs.
      The module's unit tests got their own fixtures under `tests/unit/DurableModule/Fixture/`,
      which is where test material belonged in the first place — they exercise the declaration
      mechanism, not any particular workflow.

- [x] 3bis.2 **One PSR-4 root, and the special case disappears.** The module is
      `Gplanchat_DurableModule`, the package autoloads under `Gplanchat\DurableModule\`, and the
      second `psr-4` entry that existed only for `Controller/` is gone. Magento composes an admin
      action from the *module name*; once that name and the PSR-4 root agree there is nothing extra
      to declare. Author's decision — the earlier shape treated the symptom.

- [x] 3bis.3 **The admin screen uses Magento's standard grid, and gained a detail view.**
      The first version was a hand-written `<table>` in a template — not a decision, just the
      shortest thing that rendered. It is now a `ui_component` listing over a custom
      `AbstractDataProvider`, which is the documented way to feed a grid from something that is not
      an SQL collection: the operator gets the paging, bookmarks, column controls and export they
      know, and none of it is reimplemented.
      ⚠ **Paging is the friction, and it is bounded rather than hidden**: the grid pages by offset,
      the cluster by continuation cursor, and the two do not translate without state. The provider
      reads a **200-run window** and pages inside it; the way out, when it bites, is to remember
      cursors per page in the admin session — not a bigger window. Filtering says the same thing:
      it filters the window, not the cluster, whose visibility query is a surface of its own.
      The detail view (`durable/process/view`) reads `readHistory()` — the same port the Sylius
      dashboard renders — and shows the run's journal: 23 events for a completed order on the bench.

- [x] 3bis.4 **The status filter is a multi-select, and the filters actually filter.** `status`
      becomes a `select` column whose options come from `WorkflowRunStatus::cases()` — the enum is
      the source, so an added status appears in the filter without anyone remembering to add it —
      and `listing_filters` carries the core's `ui/grid/filters/elements/ui-select` template, which
      is what turns one choice into several. `addFilter()` therefore accepts both shapes the widget
      sends: a string for one box ticked, an array for several.
      ⚠ **Two bugs, both found by measuring rather than by reading.** The action column rendered
      empty cells without raising, because `foreach ($x['data']['items'] ?? [] as &$item)` takes a
      reference into a *temporary* — the `??` has to go, replaced by an `isset()` guard. And every
      filter was a no-op because `getData()` still ran the first version's single `workflowName`
      branch and never called the new `applyFilters()`: dead code that looked like live code.
      Measured on the bench, 18 runs: `completed` → 5, `running` → 13, `completed,running` → 18,
      `failed` → 0, `failed,cancelled` → 0, `workflow_name ~ slow` → 5.

- [x] 3bis.5 **Chaque ligne du journal se déplie, et la frise dit l'attente.** L'écran de détail
      répondait « quoi » et jamais « avec quoi », parce que le port ne portait pas la réponse :
      `WorkflowRunEvent` n'avait que séquence, horodatage, voie et libellé. Il gagne un
      `details` en fin de constructeur — additif, la classe est `final readonly`, aucun appelant
      existant ne bouge. Le journal le remplit avec `Event::payload()`, qui est **sur l'interface**
      et n'a donc rien coûté ; le pont Temporal sérialise les attributs de l'événement d'historique,
      qui sont un `oneof` — le nom du champ renseigné se lit sur `getAttributes()`, ce qui évite
      d'énumérer cinquante formes.
      ⚠ **Les charges utiles seraient arrivées en base64** (`Payload.data` est un champ `bytes`) :
      elles sont relues par-dessus avec `Codec/JsonPlainPayload`, celui-là même qui les a écrites.
      Mesuré sur la grappe : 16 événements sur 16 dépliables, et le `durableAppend` montre
      l'événement métier qu'il transporte — `ActivityScheduled`, `durable.demo.charge`,
      `{"orderId": "ORD-4242"}` — au lieu d'un bloc opaque.
      ⚠ **La frise a fait tomber un défaut du pont** : `recordedAt` ne gardait que
      `getSeconds()` de l'horodatage Temporal. Seize événements séparés de quelques millisecondes se
      lisaient au même instant, et une frise construite là-dessus empile tous ses repères au même
      endroit. Les nanosecondes sont désormais tronquées à la microseconde, là où PHP s'arrête.
      La frise elle-même est du CSS : une voie par nature, un repère par événement placé à
      `(t - t₀) / durée`. Sur une commande du banc, 23 événements sur 24 secondes : **91 % de la
      frise est un trou** entre la planification de la tâche et son démarrage — un fait que la liste
      de 23 lignes régulièrement espacées cachait activement.
      *La suite a ouvert ce port : voir 3bis.6.*

- [x] 3bis.6 **Une ligne par action, pas par nature — et la barre est la durée.** La première frise
      rangeait par voie (« les activités », « les signaux »), ce qui obligeait l'exploitant à
      recoller trois repères de l'œil pour savoir combien de temps *celle-là* avait duré. Une
      activité planifiée, démarrée puis terminée est **une action et trois événements** ; le port
      gagne donc un `actionKey`, et `null` y est une réponse — « cet événement est à lui seul son
      action » — et non une absence.
      Le lien existait déjà des deux côtés, c'est la traduction qui le jetait : le journal corrèle
      par `activityId` / `timerId` / `scheduledEventId` ; Temporal corrèle par **numéro
      d'événement**, tout ce qui suit une planification la désignant par `scheduledEventId`,
      `startedEventId` ou `initiatedEventId` — trois accesseurs cherchés dans cet ordre, et
      l'événement fondateur qui ne désigne personne se désigne lui-même.
      ⚠ `getParentInitiatedEventId` est **volontairement hors de la liste** : il pointe vers
      l'histoire du parent, et le confondre rattacherait le démarrage d'une exécution enfant à un
      numéro d'un autre journal.
      Au passage, un minuteur porte enfin son résumé : `TimerScheduled` nommait la classe, pas
      l'attente.
      Mesuré sur la commande du banc : 23 événements → **9 actions**, dont
      `WORKFLOW TASK SCHEDULED` **22,0 s** (le worker n'était pas là), `durable.probe.reserve`
      2,0 s, `durable.probe.charge` 11 ms. Rendu à travers Magento : 9 lignes, 9 barres, 23
      dépliants.

- [x] 3bis.7 **La première ligne est l'exécution, les enfants gardent la leur, et les lignes portent
      un nom.** Trois corrections d'un coup, sur demande de l'auteur. Les libellés en capitales
      (`WORKFLOW EXECUTION STARTED`) nommaient une classe d'événement, pas ce qui tourne : côté
      Temporal, une seule règle — *un événement qui nomme un type de workflow nomme sa ligne* —
      couvre l'exécution **et** ses enfants sans table de correspondance, parce que
      `getWorkflowType()` est porté par les deux ; côté journal il n'y a qu'un flux, donc le nom
      vient de l'appelant, qui tient déjà la `WorkflowRunDescription` — `read($runId, $workflowName)`,
      argument optionnel, les trois catalogues le passent.
      Les tâches de workflow sont la plomberie du moteur, pas un fait métier : quatre lignes
      `WORKFLOW TASK SCHEDULED` noyaient les quatre lignes intéressantes. Elles rejoignent la ligne
      de l'exécution.
      ⚠ **Les exceptions sont l'essentiel de la règle.** `WORKFLOW_EXECUTION_SIGNALED` et la famille
      `WORKFLOW_EXECUTION_UPDATE_*` partagent le préfixe de l'exécution sans en être ; les workflows
      enfants et externes ne le partagent pas, et c'est tout ce qui leur laisse leurs lignes. D'où
      un test qui éprouve le partage **type par type** à partir de l'énumération du serveur, plus un
      troisième qui échoue si un type n'est rangé nulle part — il a d'ailleurs immédiatement trouvé
      treize types que j'avais oubliés.
      ⚠ **Le regroupement effaçait le seul fait intéressant du banc.** Une fois les tâches de
      workflow repliées, la barre de la première ligne couvre toute la durée du run et dit « le run
      a duré le temps du run » : les 22 s d'attente d'un worker disparaissaient dedans. La barre est
      donc **découpée entre événements consécutifs**, chaque segment portant son intervalle —
      `22,0 s · #2 → #3 · WORKFLOW TASK SCHEDULED → WORKFLOW TASK STARTED`. Mesuré : 23 événements →
      **4 actions**, 19 segments, et l'attente est nommée.
      Le tableau de bord Sylius est aligné dans le même mouvement : `lanes` devient `actions`, un
      bloc par action avec son nom et sa durée, la bordure gardant la couleur de la nature.
      C'est **Psalm qui a trouvé la duplication** : deux `match` identiques, un par hôte, tombant
      tous les deux sur `strictBinaryOperands`. Le seuil à partir duquel une seconde vaut mieux
      qu'une milliseconde est une décision, pas un détail de gabarit — pris deux fois, la même
      exécution se lit « 2.0 s » sur un hôte et « 2004 ms » sur l'autre. D'où
      `Observation\ReadableDuration`, à côté du modèle dont il met en forme les faits, comme
      `WorkflowRunEvent::$label` et pour la même raison.

- [x] 3bis.8 **L'attente de prise en charge est hachurée.** Une barre dit une durée, pas ce qui a
      été fait dedans : vingt-deux secondes à attendre un worker et vingt-deux secondes à exécuter
      dessinaient le même rectangle, alors que c'est la première question de l'exploitant devant
      une exécution lente — mon code, ou personne au bout du fil ?
      Le port gagne `started`, « le travail commence ici » ; ce qui précède un tel événement à
      l'intérieur de son action est une file, pas du travail. Le segment hérite du `started` de
      l'événement qui le **ferme**.
      Une règle de chaque côté, et volontairement la même : Temporal nomme `_STARTED` tout
      événement par lequel un worker prend la main, le journal maison n'en a que deux.
      `WORKFLOW_EXECUTION_STARTED` / `ExecutionStarted` y tombent des deux côtés et c'est **inerte**
      — premier événement, rien ne le précède ; les inclure coûte zéro et évite une divergence qui
      aurait fini gelée dans un test.
      ⚠ **Le rendu était le vrai risque.** Les barres sont à `opacity: .35` et une file de quelques
      millisecondes tient dans les deux pixels du `min-width` : des hachures translucides sur deux
      pixels ne se distinguent plus de rien. La variante passe à `.75`, pas de trame 3 px, et sa
      règle est placée **après** celles de nature — qui posent `background` en raccourci et
      remettraient `background-image` à zéro.
      Mesuré à travers Magento (HTTP 200, 96 791 o) : 4 actions, 19 segments, **7 hachurés /
      12 pleins**, et le segment de 22,0 s est bien l'un d'eux. Les six autres attentes vont de 4 à
      14 ms, réduites au sliver : c'est l'opacité qui les distingue, pas la trame — les élargir
      ferait passer une attente de 4 ms pour plus longue qu'un travail de 6 ms.

- [x] 3bis.9 **Le journal nomme l'action, les minuteurs annoncent leur délai, et ce qui a raté est
      rouge — mesuré sur une exécution qui contient tous les cas.** Trois demandes de l'auteur, et
      une quatrième qui les rendait vérifiables : le banc n'avait qu'un chemin heureux, donc rien
      n'avait jamais prouvé que la page savait montrer un échec.
      D'où `EveryCaseWorkflow` **dans le module du banc, pas dans le paquet** (§3bis.1) : une
      activité qui réussit, une instable, une condamnée, un minuteur de 5 s, deux workflows enfants
      dont un qui échoue. ⚠ Deux cas manquent délibérément — un *signal* demande un émetteur que le
      runtime de l'hôte n'expose pas, et *Nexus* demande deux applications, qui appartiennent à
      `change/demo-nexus-deux-applications`.
      **La colonne « Action » n'a rien demandé aux lecteurs** : c'est le libellé de l'événement qui
      ouvre l'action, celui-là même que porte la ligne de frise — si bien qu'une ligne de l'un se
      retrouve dans l'autre. Côté Temporal, `ACTIVITY TASK STARTED` cachait le nom que l'exploitant
      cherchait ; le journal maison le prêtait déjà, la page ne le montrait pas.
      **Un minuteur n'a pas de nom métier**, son délai est le seul fait qu'il porte : `TIMER STARTED`
      devient `timer 5.0 s`. ⚠ Côté journal, `TimerScheduled::scheduledAt()` est une **échéance
      absolue** : soustraire sans garde annoncerait un demi-siècle d'attente sur un journal sans
      horodatage d'enregistrement.
      **Le rouge marque l'événement, pas l'action** — une activité reprise du troisième coup porte
      du rouge et se termine bien. ⚠ **Une annulation n'en est pas** : c'est une issue, et peindre
      les deux pareil vide le rouge de son sens. Deux suffixes couvrent Temporal (`_FAILED`,
      `_TIMED_OUT`) et sept classes couvrent le journal — ⚠ qui écrit `Cancelled` là où Temporal
      écrit `CANCELED`, ce qui fait rater une règle écrite d'un seul côté. Le test piloté par
      l'énumération du serveur a immédiatement trouvé le piège inverse :
      `REQUEST_CANCEL_EXTERNAL_WORKFLOW_EXECUTION_FAILED` parle d'annulation et **est** une panne —
      la demande d'arrêt n'est pas passée, l'exécution visée tourne toujours.
      ⚠ **La combinaison hachuré + rouge est inatteignable** par construction : un type ne peut pas
      finir à la fois en `_STARTED` et en `_FAILED`. Le `background-color` de la règle rouge reste
      néanmoins volontaire — le raccourci `background` effacerait la trame.
      **Et la sonde a trouvé un défaut de regroupement au passage** : la fin d'une exécution enfant
      porte `initiatedEventId` **et** `startedEventId`, et l'ordre de recherche prenait le second —
      qui désigne le démarrage de l'enfant, pas l'événement fondateur. Chaque enfant occupait donc
      **deux lignes**, dont aucune ne disait sa durée. `getInitiatedEventId` passe avant
      `getStartedEventId`. Mesuré : **9 actions → 7** sur la même exécution.
      Le tableau de bord Sylius prend le rouge dans le même mouvement ; il n'a besoin ni de la
      colonne — ses blocs sont déjà nommés par action — ni du délai, qui lui arrive par le libellé.
      Recette, à travers Magento (HTTP 200, 150 325 o) : 7 lignes, 3 repères rouges, 2 lignes de
      journal rouges, la colonne Action nommant les 3 lignes de chaque activité, les 2 du minuteur
      et les 6 des enfants, et les deux légendes.
      ⚠ **La sonde a aussi trouvé une panne qui ne relève pas de cet écran** et qui est rapportée
      telle quelle : sur Temporal, `flaky` consomme ses trois tentatives en deux secondes **sans que
      le code de l'activité soit rappelé** — `attempt: 3` côté serveur, une seule invocation côté
      banc. Sur le backend en mémoire, la même activité se reprend et réussit. Rapportée dans
      l'issue #218, pas corrigée ici : c'est du cœur, pas du module Magento.

## 4bis. What the CI can see of Magento

- [x] 4bis.1 **A Mage-OS × PHP matrix, the counterpart of the Symfony one.** Five entries, each an
      edge with a reason: the oldest line that still accepts the module's PHP floor, the bench's
      pin at that floor, the top of the 2.x line under a recent PHP, the 3.x floor — where Mage-OS
      refuses PHP 8.2 while the module allows it — and newest on newest. It proves the module's
      constraints are honest against each line; it does not prove boot, which costs ~1 GB per
      entry and belongs to an integration job.
      Verified to discriminate before it was written: `2.2.0` on PHP 8.2 resolves, `3.4.0` on PHP
      8.2 fails naming the cause.
- [x] 4bis.2 **A job that boots it.** One job, not matrixed — the distribution is ~1 GB and the
      install takes minutes, which is exactly why there is one edge and not five. MySQL and
      OpenSearch as services, `composer install` through the bench's tracked lock so it installs
      **this commit** and not a published version, then `setup:install`, `module:status`,
      `durable:demo` asserting both `notify:charge:ORD-4242` **and** `durable.demo.charge` — the
      second is what proves the activity names come from the contract's attributes — and the admin
      answering over HTTP.

- [x] 4bis.3 **L'analyse statique entre dans le module, contre les vraies classes de Magento.**
      `src/DurableModule` était le seul paquet publié hors des chemins de PHPStan et de Psalm : les
      jobs prouvaient qu'il démarre, rien ne relisait son code — et c'est là que vivent le bloc
      d'administration et la frise.
      ⚠ **Il ne pouvait pas rejoindre `phpstan.neon`** : le module touche seize classes de Magento
      que ce dépôt n'installe pas, et les y ajouter nu donnait 47 `class.notFound`. Deux issues se
      présentaient. Écrire des bouchons pour ces seize classes — **écartée** : un bouchon
      approximatif ne fait pas d'erreur, il en cache, parce qu'il rend l'analyse d'accord avec
      lui-même. Ou faire tourner l'analyse là où la distribution est déjà installée : le job qui
      démarre le banc, qui vient précisément de payer le gigaoctet. D'où `phpstan-magento.neon` et
      `psalm-magento.xml`, mêmes niveau et mêmes suppressions que les configurations principales —
      deux sévérités pour un même dépôt feraient de la propreté d'un paquet une affaire de
      configuration.
      Le cœur est en `scanDirectories` et non en `paths` : découvert, pas analysé — il l'est déjà
      ailleurs.
      **Six trouvailles, toutes réelles**, aucune faite taire :
      — `Result\Page::setActiveMenu()` sur deux contrôleurs. Le conteneur réécrit la fabrique :
      `module-backend/etc/adminhtml/di.xml` passe `instanceName = Backend\Model\View\Result\Page`
      à `Framework\View\Result\PageFactory`. Une annotation `@var` dit à l'analyse ce que le
      `di.xml` fait, plutôt que d'ignorer l'erreur ;
      — `Title::prepend()` déclare `string`, `__()` rend une `Phrase`, deux fois. Le titre est rendu
      là de toute façon : la conversion respecte le contrat sans coûter de traduction tardive ;
      — `Filter::getValue()` est annoté `@return string` **et c'est faux** : le `ui-select` du
      filtre d'état rend un tableau dès la deuxième case cochée, mesuré en §3bis.4. La garde reste,
      l'annotation dit ce qui arrive vraiment ;
      — `microtime(true) + $timeLimit` mélangeait flottant et entier sous l'œil strict de Psalm.
      ⚠ Une septième erreur vient **du code de Magento** — `AbstractDataProvider` annote
      `@return null` là où l'interface promet un `SearchCriteriaInterface`. La suppression est
      bornée au vendor du banc : la même règle reste active sur `src/DurableModule`.
      ⚠ **Et un piège du même genre que celui des splits**, payé en six essais : deux copies du cœur
      sont joignables — l'autoloader racine mène à `src/Durable`, celui du banc à
      `magento/vendor/gplanchat/durable` — et c'est sans danger *uniquement parce que le dépôt
      `path` du banc lit `../src`, donc le même commit*. Un banc installé plus tôt fait dire à
      l'analyse que des propriétés ajoutées depuis n'existent pas.
      Vérifié après coup sur le banc et pas seulement par l'analyse : les deux écrans
      d'administration rendent toujours en HTTP 200, titres compris — ce sont exactement les deux
      fichiers dont une casse serait invisible aux deux outils.

## 5. Temporal, end to end

- [x] 5.1 **Where the journal lives is decided by the presence of a DSN.** `RuntimeFactory`
      (renamed from `InMemoryRuntimeFactory`) assembles an `InMemoryEventStore` without one and a
      `TemporalJournalEventStore` with `durable/temporal/dsn` in `env.php` — a connection string,
      not the backend-name surface §2.3 removed. It stays constructible without Magento, which puts
      the decision under CI. **The worker is built**: `bin/magento durable:worker` polls the journal
      task queue and completes workflow tasks, bounded by `--max-tasks` and `--time-limit` for the
      supervisor that restarts it. A command and not a queue consumer, because §1.5 measured what
      Magento does to a message held too long. ⚠ **The grid still reads `running` for every run,
      and the worker is not the reason**: a `DurableJournal` workflow is long-lived by construction.
      A truthful status must come from the journal's events, not from the Temporal workflow status —
      the history cursor is wired for it, and the dashboard change owns the rest.
- [x] 5.1b **An admin screen: `System > Durable processes > Process history`.** Route, ACL, menu,
      controller, block and template, reading `InMemoryWorkflowRunCatalog` over whatever event store
      the factory assembled — the same core observation the Sylius dashboard renders, so nothing is
      reimplemented. Verified over HTTP in both states: without a DSN it says so and explains why an
      empty list is the correct answer; with one, the warning goes and it reads the cluster.
      *Landed here on the author's instruction rather than in the separate dashboard change; that
      change remains the home for run detail, filters and backend health.*
      ⚠ **A catalog is not derivable from a journal**, and the first version got this wrong:
      `InMemoryWorkflowRunCatalog` keeps its own map, fed by `recordStart()`/`recordOutcome()` in
      the process that executes. An admin request executes nothing, so the grid was empty while a
      run had just completed against the cluster. Listing a cluster's executions means asking the
      cluster — `TemporalWorkflowRunCatalog`, which the bridge already ships.
- [x] 5.2 **An order placed starts an execution on the cluster, and it completes.** An observer on
      `sales_order_place_after` — the event Magento actually emits, the same from checkout, REST or
      admin — calls `startAsync()` and returns; starting the workflow in the request would kill it
      with the request, which is OST003's failure exactly. Order `000000001` produced
      `durable-order-000000001` on the cluster, the workers drove it to
      `'notify:charge:000000001'`, one charge.
      The observer never throws: a placed order stays placed. It lives in the bench, because which
      workflow starts on which order is a project's decision; the module ships the door.
      ⚠ The order is created through Magento's own order API rather than clicked through checkout —
      the *event* is the real one, the click is not simulated. And the grid now reads the business
      workflow type with a `completed` status, which corrects what this change said twice: the
      `DurableJournal`/`running` reservation belongs to the in-process path only.
- [x] 5.3 **The failure OST003 names is gone, measured end to end.** An execution started on the
      cluster, both workers killed with `kill -9` during the stock reservation, both restarted:
      the order completes — `'notify:charge:ORD-acceptation-…'` — and the card is charged
      **exactly once**.
      It needed two things, not one: an activity worker (`--role=activity`), because on Temporal an
      activity is a task somebody must take; and a way to start an execution **on the cluster**
      (`WorkflowClient::startAsync()`), because `MagentoRuntime::run()` executes in-process and its
      activities die with it whatever journal sits underneath. The first alone would have changed
      nothing.
      *Previously, and kept for the record:* Killed with `kill -9` mid-reservation and restarted under the same execution id,
      three times: **the card is charged exactly once**. The journal replays what it recorded. What
      does *not* yet happen is the execution running to completion — `WorkflowStuckException`,
      because `reserve` was dispatched into the dead process's in-memory activity transport and
      nothing in the new process will settle it. ⚠ **Resuming needs durable activity dispatch**,
      which is task 4. This entry stays open, and it now says exactly what closes it.
      Instrument: `magento/probe-resume.php` plus a slow, charge-recording workflow in the bench
      probe module — the §3.1 declaration mechanism used by a third party, which proves it too.
      Found on the way: **the core imported the Symfony bundle** (`TimerWakeDelayCalculator`), a
      fatal error on any host without it. Moved to `Gplanchat\Durable\Timer\`, with its migration
      procedure, and a guard over the 183 files of `src/Durable` so it cannot come back.

## 6. Say it landed

- [x] 6.1 **DUR046** — the package name and the one thing it costs (Magento resolves a controller
      from the *module name*, not the autoloader); two backends refused by **Composer** rather than
      by code, and a first version of that refusal deleted; why nothing rides Magento's queue and
      why the workers are commands; the lock, shared, whose use case evaporated with the queue; and
      the three things this host integration moved into the core — including a **fatal** dependency
      of the core on the Symfony bundle that only a non-Symfony host could see.
      It also says what it does not claim: the package is unpublished, CI resolves but does not
      boot, and Adobe's distribution is untested.
- [x] 6.2 The home page selector drops the `?` from `gplanchat/durable-magento`, and its state stops
      being `planned`. Through the canvas, not the generated file.

      Two attributes in each of the two committed `.dc.html` canvases, and nothing else. The chip's
      inline style and its "Coming soon…" badge are **repainted at runtime** from `data-state`
      (`CHIP_DIS` / `CHIP_ON` / `CHIP_OFF`), so `data-state="ok"` is the whole edit — copying
      Sylius's solid border by hand would have been a second source of truth for the same fact.

      `data-family="Magento"` stays. It is a canvas control, not markup: `syncIntegrations()` reads
      a `releaseMagento` prop every 1.2 s and writes `data-state` from it. That component does not
      survive the import, so only the static attribute reaches the served page — but removing the
      hook would break the toggle for whoever opens the canvas next.

      **The regeneration is the proof WA005 was written for.** Re-running `import-design.py` on both
      canvases changed **four lines in total**, two per page, and they are the two edits. The canvas
      and the pages were genuinely in sync; the guard had nothing to refuse.

      ⚠ **This must not be merged before `gplanchat/durable-magento` is on Packagist.**
      `check_packages_resolve()` passes, and it is right to: it reads `src/*/composer.json`, which
      answers "does this repository declare the package". Packagist answers a different question,
      and today it answers 404. Merging first would put a `composer require` on the home page that
      does not resolve — the exact failure that guard exists for, in the one place it cannot see —
      and would make the home page contradict `documentation/user/packages/`, which still carries
      its **Pas encore publié** warning.
- [x] 6.3 **Both languages carry Magento.** `documentation/user/packages/` gains a
      `gplanchat/durable-magento` section — declaration by `di.xml`, the contract that is *not*
      declared, the two backends Composer enforces, the DSN that decides, workers as commands, and
      the note that executions start on the cluster and not in the request. The Backends page says
      why the SQL row does not exist on this host.
      ⚠ Each section opens with a **warning that the package is not on Packagist**: documenting a
      `composer require` that does not resolve would be the documentation telling a lie the rest of
      this change spent its time avoiding.
- [x] 6.5 **Le paquet est publié — `github.com/gplanchat/durable-magento`, `main` à `edcce1c5`.** Ce qui est dans le dépôt est fait : `src/DurableModule/` gagne son
      `README.md` et sa `LICENSE` — les six paquets publiés en ont, celui-ci était le seul sans — et
      `bin/splitsh-publish.sh` gagne sa ligne `"src/DurableModule/|durable-magento"`. `composer
      validate --strict` passe sur le manifeste.
      ⚠ **Le satellite existe déjà, et il n'est pas vide.** `gplanchat/durable-magento` a été créé
      le 2026-03-29 et porte un `main` : le split de `af3e51be`, quand ce préfixe tenait un tout
      autre module (`Api`, `Model`, une commande de consommation), depuis retiré par `e9b24e9c`.
      Son arbre correspond exactement à `src/DurableModule/` à ce commit-là, donc c'est un vrai
      split du même préfixe.
      ⚠ **J'en ai déduit que le split d'aujourd'hui l'aurait pour ancêtre. C'était faux, et la
      poussée l'a dit** : `non-fast-forward`, seul satellite refusé sur dix. La suppression du
      préfixe par `e9b24e9c` a coupé la chaîne — un préfixe vide ne produit pas de commit, donc
      splitsh est reparti d'une racine neuve à la re-création. **Un split d'un même préfixe n'est
      pas un ancêtre garanti d'un split ultérieur : il ne l'est que si le préfixe n'a jamais
      disparu entre les deux.**
      Réparé sans forcer et sans toucher aux neuf autres : `refs/heads/archive/pre-force-f1462f17ec`
      créé sur la tête de mars, branche par défaut basculée dessus, `main` supprimée, Splitsh
      relancé en mode **normal** — qui crée `main` proprement — puis branche par défaut remise. Le
      `workflow_dispatch` avec `force` aurait marché aussi, mais il force les dix et laisse neuf
      branches d'archive sur des dépôts publics déjà alignés.
      ⚠ **Le satellite est PRIVÉ**, seul des dix à l'être. Packagist ne lira rien tant qu'il ne sera
      pas public.
      ✅ Dépôt rendu public et ajouté à la portée du PAT `SPLITSH_PUSH_TOKEN` par l'auteur ; la
      portée s'est prouvée au premier essai — le refus était un refus **git**, pas un 404 ni un 403.
      **Reste : soumettre à Packagist**, le dernier geste hors du dépôt.
      ⚠ **Un préfixe ajouté après coup ne rattrape pas les tags passés** : le paquet arrivera avec
      `dev-main` et **zéro version**, exactement comme `durable-bridge-illuminate` l'a fait le
      2026-08-28. Il prendra sa première version au prochain tag, pas en rejouant `v0.1.0-alpha7`,
      qui le ferait apparaître dans une version qui ne le contenait pas. D'où la section *Release
      state* du README, qui dit `:dev-main` plutôt que de laisser croire.
      L'avertissement « pas sur Packagist » des pages de documentation reste tant que Packagist ne
      l'a pas : il tombera avec la soumission, pas avec cette PR.

- [x] 6.4 **OST004's Magento row has left the "not built yet" table** — struck through in both
      tables, marked settled, pointing at DUR046 and naming what is still missing (publication, a
      CI job that boots). OST003 §Magento becomes *§Magento — built*, and carries the two findings
      worth taking to the next host: nothing rides the host's queue when the backend is a cluster,
      and a genuinely foreign host corrected the core three times, once fatally.
