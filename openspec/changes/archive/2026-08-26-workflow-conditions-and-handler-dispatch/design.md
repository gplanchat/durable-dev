## Context

Two things are missing, and they turn out to be one thing.

A handler that pushes — the engine calls it, it mutates workflow state — cannot wake the workflow
body, because nothing waits on a predicate. And a wait on a predicate has no compelling use until
something pushes state into the workflow between wakes. Neither half is worth building alone.

`#[SignalMethod]` and `#[UpdateMethod]` exist as attributes and are read by nothing; only
`#[QueryMethod]` is wired, through `WorkflowDefinitionLoader::registerQueryHandlers()`. There is no
dispatch to modify — there is dispatch to build.

## What was probed, and what was assumed

Per the house rule, the boundary between observed and assumed:

- **Observed in the code, not the server:** the Temporal worker does not handle updates at all.
  `WorkflowTaskProcessor` says signal/query/update handling "will be added in the
  signal-query-update phase". `TemporalExecutionHistory` reads `WORKFLOW_EXECUTION_UPDATE_ACCEPTED`
  and `..._UPDATE_COMPLETED`, and `WorkflowClient` can send `UpdateWorkflowExecution` — so an update
  can be sent and its recorded result read back, but nothing produces one.
- **Observed by reading the current code, and it is what makes this change cheap:** the `Awaitable`
  contract is now exactly `isSettled()` and `getResult()`, and `awaitUnderDeadline()` calls nothing
  else on its branches. A condition therefore *is* an awaitable — `isSettled()` is the predicate —
  and the deadline path does not fork.
- **Probed, whole round trip (task 1.3).** An update reaches a worker **outside the journal**, as
  a `temporal.api.protocol.v1.Message` on `PollWorkflowTaskQueueResponse.messages` — not as a
  history event. The message carries `id = <update_id>/request`, `protocol_instance_id =
  <update_id>`, a body of `Any(update.v1.Request)` with the name and arguments, and it is anchored
  on the `event_id` of the task's `WORKFLOW_TASK_SCHEDULED` plus a `command_index`.

  The worker answers **on that same task**, in `RespondWorkflowTaskCompleted`: two messages — an
  `Acceptance` (echoing `accepted_request_message_id`, `accepted_request_sequencing_event_id` and
  the original request) and a `Response` (`meta` + `outcome.success`) — plus one
  `COMMAND_TYPE_PROTOCOL_MESSAGE` command referencing the acceptance's message id. No second task
  is needed.

  The caller then receives stage `COMPLETED` and the outcome payload, and history gains
  `WORKFLOW_EXECUTION_UPDATE_ACCEPTED` followed by `WORKFLOW_EXECUTION_UPDATE_COMPLETED` — exactly
  the two events `TemporalExecutionHistory` already reads. **The replay side is already built; what
  is missing is entirely the worker-side protocol.**

  Two server rules learned by being refused: `Meta.update_id` is mandatory on the request, and
  waiting on the `ADMITTED` stage is rejected outright ("issued asynchronously and waiting on
  update admitted is not supported"), so an update cannot be observed without something to accept
  it.
- **Probed, and the answer is yes (task 1.2).** A Temporal workflow task can carry several
  journaled messages at once. Against the running server, on a task queue no worker polls: three
  signals sent in a row produce **one** `WORKFLOW_TASK_SCHEDULED` followed by three
  `WORKFLOW_EXECUTION_SIGNALED` — no extra task is scheduled per signal — and a single
  `PollWorkflowTaskQueue` hands the worker all three in one task.

  The same probe run *with* a worker polling showed one signal per task, because the worker
  claimed each task before the next signal landed. Both regimes are real: **how many messages a
  task carries is a timing artefact of worker availability, not a contract.** A worker restart, a
  deploy, or a scaling event produces the batched regime in ordinary production.

  That settles the question 1.1 depended on: the task boundary cannot be used to order message
  application, because it does not reliably separate messages. The interleaving has to be enforced
  inside one replay pass.

  Le régime groupé est épinglé par `tests/integration/Temporal/WorkflowTaskMessageBatchTest.php`,
  qui compte dans le segment suivant le dernier `WORKFLOW_TASK_COMPLETED` — le poll rend
  l'historique entier, et compter dessus prouverait seulement que plusieurs signaux ont été
  enregistrés un jour, jamais qu'une tâche les transporte. La sonde établit que ce régime est
  **atteignable**, ce qui suffit : c'est lui qui interdit d'ordonner par frontière de tâche.

## Decisions

### `await()` takes a condition. There is no second wait method.

`await()` is already the single wait of this component — `2cec7a4` made every assembler return an
awaitable precisely so there would be one. Adding `awaitCondition()` would re-split the surface that
commit unified.

So a condition is a second accepted argument type, not a second method:

```php
$env->await(fn(): bool => $this->approved, Duration::hours(1));
```

This is also the sharpest answer to "aren't a signal wait and a condition two versions of the same
mechanism?" — they are not two altitudes of a mechanism, they are one method with one more accepted
argument type.

The wrapper is three lines, because the awaitable contract already is a condition:

```php
public function isSettled(): bool { return ($this->predicate)(); }
public function getResult(): mixed { return null; }
```

### `waitSignal()` and `waitUpdate()` are removed, and that is the point

A signal wait reads history directly. That is what forced all of it: a positional slot, a per-name
consumption counter, `releaseSignalWaitSlot()` for a wait that gave up without consuming, an
order-aware history lookup so a signal recorded after a deadline fired cannot settle the wait it
bounded, and an alignment between the two backends on what a slot means — they disagreed.

Under handler dispatch none of that has anywhere to live. The handler deposits into workflow state;
the body observes that state through a condition. The counter becomes `$this->approvals[] =
$payload` and `array_shift()`, owned by the workflow, obviously correct, and not an engine rule.

Rejected: keeping `waitSignal()` as sugar over an auto-registered buffering handler. It preserves
compatibility and deletes most of the machinery, but the moment a *declared* handler exists for the
same name, "who consumes it" is back — two paths again, which is the thing being removed.

### Evaluation is interleaved with message application, not batched (task 1.1)

This is the decision the change turns on, and the one that can break DUR032 silently.

DUR032's rule — a signal recorded after the deadline fired does not settle the wait that deadline
bounded — is a *history query* today. A predicate cannot be asked that: there is no way to
interrogate a closure for "did you become true before journal position P".

**A journal position is the rank of an event in the recorded stream of one execution.** In-memory
that is its index in `readStream()`; on Temporal it is the `eventId`, which
`TemporalExecutionHistory` already records for `TIMER_FIRED` and `WORKFLOW_EXECUTION_SIGNALED`.
The two numbering spaces are **not** comparable with each other and a position SHALL never be
serialized or carried across backends — every comparison happens inside one execution's own
history, where both backends are totally ordered.

#### The rule

A wait, bounded or not, drives a **cursor** over the message events of its execution — the signals
and updates, in recorded order. At a wait:

1. **P** is the journal position of the deadline timer's completion, read from history, or infinity
   when there is no deadline or the timer has not fired.
2. Evaluate the predicate as it stands. If it holds, the wait is settled at the current position.
3. Otherwise, while the next unapplied message has a position **below P**: apply it, dispatch its
   handler, re-evaluate. Stop at the first message that makes the predicate hold — **Q** is *that
   message's* position.
4. Predicate holds → the wait returns. Timer settled and predicate still false → the deadline
   failure. Neither → suspend, unchanged.

The cursor is per-execution state, rebuilt from zero on every replay pass, advanced by the same
rule over the same journal — so Q and P are the same on every pass, and so is the verdict.

#### Both positions must be stream positions, not two different counts

The trap is to let **Q** be "how many messages have been applied" while **P** is a stream rank.
Those count over different subsets, and comparing them is comparing unlike things. Against the
journals already hand-built in `WorkflowDeadlineTest`:

| journal (stream ranks) | P | Q as stream rank | Q as message count | expected |
|---|---|---|---|---|
| `Started, TimerScheduled, TimerCompleted, Signal` | 2 | 3 → deadline | 1 → **signal, wrong** | deadline |
| `Started, TimerScheduled, Signal, TimerCompleted` | 3 | 2 → signal | 1 → signal | signal |
| `Started, TimerScheduled, TimerCompleted` | 2 | ∞ → deadline | ∞ → deadline | deadline |
| `Started, Signal` | ∞ | 1 → signal | 1 → signal | signal |

The message count gets the first row wrong, and gets the second right only by coincidence. **Q is
the stream position of the message that satisfied the predicate.**

#### The wait drives the cursor, never `isSettled()`

`AnyAwaitable::isSettled()` returns true as soon as *any* member is, and `ExecutionRuntime::await()`
short-circuits on it. So on a replay where the timer completion is in history, the composite reports
settled before anything has advanced the cursor: the condition would never be evaluated, and the
deadline would win every time.

The cursor is therefore driven by the wait itself — the loop above runs **before** the composite is
handed to the runtime — and `isSettled()` on a condition stays a pure evaluation of the predicate at
the current state. Both the bounded and the unbounded path share that loop, the unbounded one with
P = infinity.

Rejected: letting the condition advance the cursor inside its own `isSettled()`. It removes the
explicit loop, at the price of a side-effecting `isSettled()` — which nothing else in the awaitable
contract has — and it still has to be told P, so the deadline leaks into the branch anyway.

Rejected: gating *every* awaitable on the cursor, so that an activity or a timer only settles at or
before it. It is the uniform model, and it rewrites slot resolution for every operation type to buy
what a comparison of two positions already gives.

#### Consequences to state plainly

- `findTimerSlotResult()` must expose the position of the timer's completion. Temporal already has
  it; the in-memory source derives it while enumerating.
- **A handler for a message recorded after a deadline runs only when the workflow reaches its next
  wait** — after the expiry path has already run. That is surprising, and it is the same rule as
  DUR032's "the late signal remains available to a later wait", seen from the handler side. It
  belongs in the ADR.
- Nothing here needs a new event type, and nothing here is backend-specific.

### An update has no journal position until it is accepted

The interleaving rule of 1.1 orders messages by their rank in the recorded stream. A signal has
one the moment it is delivered. An inbound update does **not**: it arrives out-of-band on the task,
carrying an `event_id` anchor and a `command_index` rather than a position of its own.

This does not break the rule, because of where determinism actually matters. Accepting an update
writes the original request into history (`WORKFLOW_EXECUTION_UPDATE_ACCEPTED` carries
`accepted_request`), so from the *next* replay onward an update is journal-positioned like any
signal. Only the first execution sees it out-of-band, and there the anchor supplies the order:
after the history events up to `event_id`, then by `command_index`.

So the rule reads, precisely: **a message is applied at its recorded position when it has one, and
at its anchor when it does not yet.** A replay only ever meets the first case.

The consequence for the domain is that update dispatch cannot be fed from the history source alone
— the worker must hand the pending protocol messages to the execution for the first pass. Signals
need nothing of the sort.

### Handlers are declarable both ways, because the tests are closures

`registerQueryHandler()` already exists on `WorkflowEnvironment`, and `#[QueryMethod]` is the
declarative form the loader turns into that call. Signals and updates follow the same pattern —
this is not a concession, it is load-bearing: nearly every workflow in the test suite is a closure,
and a closure cannot carry an attribute. Without imperative registration the new primitive is not
testable in the style the suite is written in.

### An update answers; a signal does not

That is the whole difference, and it is why updates are a separate requirement rather than a
parameter. An update handler's return value is the response the caller is blocking on, so it must
survive replay: a replay reproduces the recorded response rather than recomputing it.

### Où se consigne l'issue d'un update (tâche 6.6)

Au moment où le curseur applique l'update, par le tampon de commandes — pas par l'appelant de la
passe une fois celle-ci finie.

La raison est l'ordre. L'enregistrement doit précéder ce que le workflow fait *en réponse*, sinon
un replay appliquerait les deux dans l'autre sens. C'est exactement l'ordre que Temporal impose et
que la tâche 5.5 a appris de lui : la commande d'acceptation précède les commandes du workflow. Le
tampon in-memory écrivant sans différé, consigner dans la boucle donne la position voulue.

Sur le backend Temporal, `recordUpdateHandled()` est délibérément vide : c'est le **serveur** qui
écrit `UPDATE_ACCEPTED` et `UPDATE_COMPLETED` à partir des messages de protocole renvoyés, et un
worker qui journaliserait aussi ferait double emploi. L'asymétrie est dans le docblock, faute de
quoi quelqu'un « réparera » ce vide.

Rejeté : consigner après la passe, depuis le handler de livraison. C'est la forme la plus simple,
et un signal livré pendant la passe se retrouverait enregistré *avant* l'update alors qu'il est
arrivé après — les deux seraient rejoués dans le désordre.

Le transport, lui, ne duplique rien : `ResumeWorkflowMessage` porte les updates en attente, et
`DeliverWorkflowUpdateHandler` se contente de demander une reprise qui les emporte. Tout le cycle
de vie d'une exécution — suspension, continue-as-new, clôture, remontée au parent — reste dans le
seul `ResumeWorkflowHandler`, ce que DUR027 avait consolidé et qu'il aurait été coûteux de
ré-éclater.

## Non-goals

- **Update validator methods.** Temporal separates validation from execution for updates; that is
  its own reading and its own protocol phase. Adding it later costs an attribute argument.
- **`#[QueryMethod]`.** Already wired, and untouched.
- **Changing how signals are named.** The `BackedEnum|string` widening already landed; handlers take
  the same names.
- **A compatibility shim for `waitSignal()`.** The migration is a documented rewrite, not a
  deprecation period.

## Risks

- **Silently breaking DUR032.** Interleaving is the whole mitigation, and the regression gate is
  concrete: the deadline tests must be *rewritten* onto conditions and must still assert the same
  outcomes. Where a deadline test loses its assertion instead of changing shape, the guarantee was
  lost with the method.
- **A public API is removed.** `waitSignal()` is documented, used in the Symfony samples and the
  integration fixtures, and has just received deadlines and enum names. Part of what landed this
  week is deleted on purpose; the migration snippet has to be good enough that no one has to
  reconstruct it.
- **A predicate re-run after every applied message is user code in a hot path.** A workflow with
  many pending conditions and a long history pays linearly. Worth measuring before it is documented
  as free.
- **The update half may not be buildable as specified** until the worker-side protocol is probed. If
  the probe in task 1 contradicts the spec, the spec moves — not the server.
