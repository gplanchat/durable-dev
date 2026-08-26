## 0. Unblock

- [x] 0.1 `workflow-side-deadlines` landed and archived, so `openspec/specs/workflow-deadlines/` exists and this change's removal delta has a requirement to point at
- [x] 0.2 The in-flight awaitable refactor (composite / quorum) committed — `WorkflowEnvironment` is the single entry point both changes touch

## 1. Establish the evaluation loop before writing the primitive

- [x] 1.1 Write down, in journal-position terms, the interleaving of message application, handler dispatch and condition re-evaluation, and record it in `design.md` — a position is a stream rank, the wait drives the cursor, and both P and Q are stream positions or the comparison is meaningless
- [x] 1.2 Check whether a Temporal workflow task can carry several journaled messages at once, so interleaving is an ordering question inside one task and not only across tasks — yes: three signals reach a worker in a single task when none is polling, and one per task when one is. The count is a timing artefact, so the task boundary cannot order anything
- [x] 1.3 Probe, against the running server, how a worker accepts and completes an update — which messages carry the acceptance and the response, and on which task they are returned. Nothing about update responses reaches the domain before this is seen — an update arrives as a protocol message beside the journal, and `Acceptance` + `Response` go back on the same task, one PROTOCOL_MESSAGE command carrying the acceptance
- [x] 1.4 Record what the probes changed, if anything, in `design.md` — 1.2 confirmed the interleaving is not free, 1.3 added the rule for a message that has no journal position yet

## 2. Failing tests first — conditions

- [x] 2.1 A condition that already holds does not suspend, and records nothing that would wake the execution
- [x] 2.2 A condition becomes true on a delivered message and the workflow resumes there
- [x] 2.3 Replay resumes at the same journal position and takes the same path
- [x] 2.4 A condition under a deadline that does not hold in time raises the timeout failure, and a message recorded afterwards does not undo it — the DUR032 guarantee, restated on conditions — plus son pendant : un message enregistré *avant* l’échéance satisfait bien la condition
- [x] 2.5 Two messages that each satisfy a pending condition are applied one at a time, and the workflow resumes on the first
- [x] 2.6 A condition that can never hold is reported as unable to advance, naming the condition, instead of spinning — nommée par sa position, via ReflectionFunction, sans paramètre supplémentaire
- [x] 2.7 A non-reproducible value recorded before the condition reads it is read back identically on replay

## 3. Failing tests first — dispatch

- [x] 3.1 An annotated method handles the signal it names, and the state it mutates is visible to the body
- [x] 3.2 A workflow expressed as a callable registers a handler and behaves identically
- [x] 3.3 A message with no declared handler is recorded and does not fail the execution — vert sans une ligne de code neuve : un signal que personne ne consomme dort déjà dans l’historique
- [x] 3.4 Two signals are handled in recorded order, identically on every replay
- [x] 3.5 Three deliveries of one name reach the handler three times, on a first run and on replay — un appel par livraison et par passe
- [x] 3.6 A delivery recorded while no await was pending is still observed by the next one
- [x] 3.7 An update handler's return value reaches the caller, and survives replay
- [x] 3.8 A raising update handler fails the update, not the workflow

## 4. Domain — conditions

- [x] 4.1 `await()` accepts a condition, wrapped into the existing awaitable contract, composing with the deadline path unchanged — `ConditionAwaitable::isSettled()` *est* le prédicat, le chemin d’échéance ne bifurque pas
- [x] 4.2 The evaluation loop from 1.1: messages applied one at a time, pending conditions re-tested after each — pilotée par l’attente, avant que le composite n’atteigne le runtime
- [x] 4.3 A condition that can never hold reported through the existing "cannot advance" path, naming the condition, rather than a new failure vocabulary — la suspension porte ce qu’elle attend, le runner le relaie dans `noProgress`

## 5. Domain — dispatch

- [x] 5.1 `#[SignalMethod]` and `#[UpdateMethod]` read at load time, alongside the existing `#[QueryMethod]` scan, tous deux acceptant une enum
- [x] 5.2 Imperative registration on `WorkflowEnvironment`, mirroring `registerQueryHandler()`
- [x] 5.3 Engine-side dispatch, interleaved with 4.2 — signaux et updates sur le même curseur, ordonnés par position de journal
- [x] 5.4 Update responses recorded and reproduced on replay — issue déposée sur le `PendingUpdate`, consignée par l’appelant de la passe comme le serveur écrit UPDATE_COMPLETED
- [x] 5.5 Worker-side update acceptance and completion on the Temporal bridge, from the probe in 1.3 — plus deux règles que seul le serveur enseigne : les commandes de protocole avant celles du workflow, et une commande par message

## 6. Deletions

- [x] 6.1 `waitSignal()` and `waitUpdate()` removed from `WorkflowEnvironment`
- [x] 6.2 The signal wait slot index, the per-name counter and `releaseSignalWaitSlot()` removed from `ExecutionContext`
- [x] 6.3 The deadline-aware argument on `findSignalForSlot()` removed from the port and both backends — la méthode entière, en fait, ainsi que `findUpdateForSlot()`
- [x] 6.4 Symfony samples and integration fixtures migrated to handler + condition — les deux échantillons passent à la forme déclarative, `#[SignalMethod]` et `#[UpdateMethod]`
- [x] 6.5 The deadline tests rewritten onto conditions, asserting the same outcomes — 11 tests, 16 assertions avant comme après

- [ ] 6.6 `DeliverWorkflowUpdateMessage` perd son `result` : la livraison in-memory exécute une passe et laisse le handler produire l'issue, comme le worker Temporal accepte et répond sur la même tâche. Découvert en migrant l'échantillon Symfony, qui fournissait encore la réponse depuis l'appelant

## 7. Backend parity

- [x] 7.1 Same workflow, same handlers, same order, in-memory and Temporal, including the replay path — `HandlerDispatchParityTest` : même journal, même suite de messages, et même classement contre le tir d’une échéance
- [x] 7.2 Integration test against a real server for a condition satisfied by a signal delivered after a deadline fired — the DUR032 case, on its new foundation : le test d’intégration a survécu à la migration sans perdre une assertion
- [x] 7.3 Integration test against a real server for an update that answers — et un update en échec qui ne tue pas l’exécution

## 8. Documentation

- [ ] 8.1 Signals documented as handler + condition, with the `waitSignal()` migration written out, because it is the rewrite every existing workflow has to make
- [ ] 8.2 Conditions documented with their determinism rule: a predicate reads workflow state and nothing else
- [x] 8.3 ADR DUR035 (DUR033 et DUR034 sont pris) : why the condition is the primitive rather than a second wait method, why evaluation is interleaved with message application, and what that let us delete
