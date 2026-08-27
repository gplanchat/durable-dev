## 1. Settle the run projection before writing the port

- [x] 1.1 Confirm on an existing install that `DurableSchema::ensure()` adds a *new* table to a database that already carries the other three, and does not touch them — the whole choice of a projection over a column rests on this — confirmed, and pinned by `DurableSchemaIncrementalCreationTest`: a missing table is created beside existing ones, an existing table keeps its rows, and a table the install has never seen appears
- [x] 1.2 List every transition that ends a run (`markCompleted`, and the three `delete()` calls in `ResumeWorkflowHandler`) and decide where the projection is written, so no ending is missed and the metadata lifecycle stays as it is — seven sites listed in `design.md`; a decorator on the metadata store is ruled out because three endings share one `delete()` call, so the name is seeded from `save()` and the outcome settled from the journal's lifecycle events
- [x] 1.3 Decide what the projection records for a run that continued as new — one row that ends and one that starts, or a chain — and note the verdict in `design.md`: **two independent rows**, no link column; the chain is carried by the port's optional grouping id, which Temporal fills and DBAL leaves absent

## 2. Failing tests first

- [x] 2.1 A run that failed on the DBAL backend is listed, named, and reads as failed
- [x] 2.2 A run that was cancelled is listed, named, and is distinguishable from a failed one
- [x] 2.3 A run that continued as new leaves both runs visible, and the one that ended is not reported as failed
- [x] 2.4 Filtering by status returns only matching runs, and the counters agree with the list — les compteurs sont vérifiés sur le modèle de vue, et **chaque** issue a le sien : `continued_as_new` comptait dans le total et dans aucun seau
- [x] 2.5 Paging through more runs than one page holds returns each run once and none twice — pagination **par clé** (date + id), pas par décalage : `started_at` est à la seconde et la table grossit pendant qu'on la lit
- [x] 2.6 Selecting a run returns its events in recorded order, with activities and signals on distinct lanes — plus l'étiquetage : une activité porte son **nom** y compris sur sa complétion, qui ne connaît que son id
- [x] 2.7 A fact the backend does not have is absent from the description — not `''`, not a placeholder — vérifié sur le modèle de vue : ni `taskQueue`, ni `groupId` quand le backend n'en a pas, et aucune voie vide dans la frise
- [x] 2.8 With no readable backend configured, the dashboard reports that and does not name Temporal — le test assure aussi que le message ne contient pas le mot
- [x] 2.9 The Temporal adapter returns what the current provider returns for the same server state — le test prouve l'identité (exécutions, identifiants, noms, ordre, curseur) **et** la divergence assumée : le fournisseur rangeait annulation et continue-as-new sous « failed », le port ne le fait plus (cf. `design.md`)

## 3. Domain

- [x] 3.1 A read port for observing runs: listing with a cursor and a status filter, and reading one run's recorded history — `listRuns()` et `readHistory()`
- [x] 3.2 The run description the port returns, with the facts a backend may not have modelled as absent rather than as empty values — `WorkflowRunDescription` + `WorkflowRunStatus` ; `groupId`, `startedAt` et `endedAt` sont nullables, jamais des valeurs de remplissage

## 4. DBAL backend

- [x] 4.1 The run projection table, declared in `DurableSchema` so it appears on installs that already exist — `durable_workflow_runs`, indexée sur `started_at`
- [x] 4.2 Writing the projection on every transition from 1.2, leaving the metadata lifecycle and `hasActiveWorkflowMetadata()` untouched — deux décorateurs, `ProjectingWorkflowMetadataStore` (le nom) et `ProjectingEventStore` (l'issue) ; aucune classe existante modifiée
- [x] 4.3 The adapter: listing from the projection, history from `durable_events` — `JournalRunHistoryReader` traduit le flux du journal, une seule passe avant suffisant à nommer les activités
- [x] 4.4 Backend reachability reported for the database, answering the same question the Temporal adapter answers about gRPC — `checkHealth()` sur le port, sondé par les deux adaptateurs, et la page ne liste rien d'un backend muet plutôt que d'afficher un tableau vide et serein

## 5. Temporal backend

- [x] 5.1 Move the dashboard's gRPC reading code out of the plugin and behind the port, unchanged in behaviour — listage **et** historique (`TemporalRunHistoryReader`). Deux écarts assumés et épinglés : le rangement des signaux et des workflows enfants, que l'ordre des tests plaçait sur la voie de l'exécution ; et la signature de `readHistory()`, qui prend la description parce que Temporal exige le workflow id (cf. `design.md`)
- [x] 5.2 Map its Temporal-shaped record onto the port's description, leaving no Temporal type in the port's vocabulary — le workflow id devient `groupId`, que DBAL laisse absent

## 6. Bundle and plugin

- [x] 6.1 Register the adapter matching the configured backend, and register none when no backend is readable — et les deux décorateurs de projection avec le catalogue DBAL, faute de quoi il lirait une table que personne n'écrit. La projection n'est câblée que si le **journal** est en SQL : c'est de lui que viennent les issues
- [x] 6.2 The plugin depends on the port instead of the Temporal bridge; the bridge leaves its `suggest` entry — achevée avec §6.3 : le paquet requiert `gplanchat/durable` et plus rien de Temporal, et suggère `gplanchat/durable-bundle` qui câble le catalogue quel que soit le backend
- [x] 6.3 Rename the view model's `temporal` key to `backend`, and render absent facts as absent in the template — no empty task queue column, no query lane where no query is recorded — et les 912 lignes du fournisseur gRPC quittent le plugin
- [x] 6.4 Move the plugin's dashboard tests onto the port, and keep one that pins the no-backend page — les trois tests du fournisseur disparaissent avec lui ; le test de parité §2.9 devient le contrat de l'adaptateur Temporal, la correction du statut restant épinglée

## 7. Documentation

- [x] 7.1 State in the DBAL backend documentation that ending a run now leaves a projection row behind, and what it holds — `durable_workflow_runs` : pourquoi elle existe, les deux plumes qui l'écrivent, les deux lignes d'un continue-as-new, et la rétention à la charge de l'application
- [x] 7.2 Rewrite the plugin README: the dashboard reads whichever backend is configured, and what it cannot show on each — fait avec §6.3, tableau des faits par backend inclus
- [x] 7.3 ADR DUR037 (renuméroté depuis DUR035, attribué deux fois) : why run observation is a projection rather than a query over the journal, and why an absent fact is modelled as absent
